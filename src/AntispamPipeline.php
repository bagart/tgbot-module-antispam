<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam;

use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;
use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Modules\ModuleSettingsContract;
use BAGArt\TelegramBot\TgApi\Types\DTO\MessageTypeDTO;
use BAGArt\TelegramBotAntispam\Captcha\CaptchaService;
use BAGArt\TelegramBotAntispam\Counters\Counter;
use BAGArt\TelegramBotAntispam\Counters\ObservationCollector;
use BAGArt\TelegramBotAntispam\Domain\AggregatedScore;
use BAGArt\TelegramBotAntispam\Domain\AntiSpamDetection;
use BAGArt\TelegramBotAntispam\Domain\EvaluationSnapshot;
use BAGArt\TelegramBotAntispam\Engine\AntispamEvaluator;
use BAGArt\TelegramBotAntispam\Engine\EvaluationOutcome;
use BAGArt\TelegramBotAntispam\Engine\PolicyCompiler;
use BAGArt\TelegramBotAntispam\Enforcement\ActionExecutor;
use BAGArt\TelegramBotAntispam\Models\AntispamStat;
use BAGArt\TelegramBotAntispam\Processors\MessageNormalizer;
use BAGArt\TelegramBotAntispam\Risk\RiskContextBuilder;
use BAGArt\TelegramBotAntispam\Rules\RuleCooldown;
use BAGArt\TelegramBotAntispam\Strike\StrikeManager;
use BAGArt\TelegramBotAntispam\UserList\UserListManager;
use BAGArt\TelegramBotAntispam\Violation\ViolationRecorder;
use Throwable;

/**
 * Webhook-path orchestration: gating → observation → compiled plan →
 * evaluation → violation → strike → async enforcement.
 *
 * Failure policy: enforcement is fail-open. Counter/Redis degradation keeps
 * content rules working; storage errors never break message processing.
 */
final readonly class AntispamPipeline
{
    public const string MODULE_ID = 'antispam';

    /** @param  list<int>  $excludeUserIds  admins/service accounts — bypass enforcement */
    public function __construct(
        private MessageNormalizer $normalizer,
        private ObservationCollector $collector,
        private Counter $counter,
        private PolicyCompiler $compiler,
        private AntispamEvaluator $evaluator,
        private RiskContextBuilder $riskBuilder,
        private UserListManager $lists,
        private ViolationRecorder $recorder,
        private StrikeManager $strikes,
        private ActionExecutor $executor,
        private RuleCooldown $cooldown,
        private ModuleSettingsContract $settings,
        private ASKLogWrapper $logger,
        private \BAGArt\TelegramBotAntispam\Engine\DbRuleOverrides $dbRuleOverrides = new \BAGArt\TelegramBotAntispam\Engine\DbRuleOverrides(),
        private ?CaptchaService $captcha = null,
        private array $excludeUserIds = [],
    ) {
    }

    public function handle(MessageTypeDTO $dto, TgBotConfig $botConfig): ?EvaluationOutcome
    {
        if (! $this->normalizer->isGroupChat($dto)) {
            return null;
        }

        [$user, $chat, $message] = $this->normalizer->normalize($dto) ?? [null, null, null];
        if ($user === null || $chat === null || $message === null) {
            return null;
        }

        $botId = $botConfig->botId;
        $chatId = $chat->chatId;
        $userId = $user->userId;

        try {
            $moduleSettings = $this->settings->settingsFor(self::MODULE_ID, $botId, $chatId);
        } catch (Throwable) {
            $moduleSettings = [];
        }

        // DB rule overrides win over platform/chat settings per rule id; both
        // feed the compiled plan (cached — no per-webhook SQL after compile)
        try {
            $moduleSettings = \BAGArt\TelegramBotAntispam\Engine\DbRuleOverrides::mergeInto(
                $moduleSettings,
                $this->dbRuleOverrides->forBot($botId),
            );
        } catch (Throwable $e) {
            $this->logger?->warning('antispam: db rule overrides unavailable', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        // Gating: whitelist = bypass module entirely (no behavioral accounting)
        if ($this->lists->isWhitelisted($botId, $chatId, $userId)) {
            return null;
        }
        $bypassEnforcement = $this->lists->isBlacklisted($botId, $chatId, $userId)
            || in_array($userId, $this->excludeUserIds, true);

        // Atomic observation pass (≤2 round trips); degrade to content-only on failure
        try {
            $snapshot = $this->counter->record(
                $this->collector->collect($botId, $chatId, $userId, $message, $dto->date),
            );
        } catch (Throwable $e) {
            $this->logger?->warning('antispam: counter degraded to content-only mode', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $snapshot = new \BAGArt\TelegramBotAntispam\Domain\CounterSnapshot();
        }

        $context = new \BAGArt\TelegramBotAntispam\Domain\AntispamMessageContext(
            user: $user,
            chat: $chat,
            message: $message,
            behavior: $snapshot->behaviorContext(),
        );

        $plan = $this->compiler->compile($botId, $chatId, $moduleSettings);
        $risk = $this->riskBuilder->build($botId, $chatId, $userId, $context->behavior);
        $outcome = $this->evaluator->evaluate($context, $plan, $risk);

        if ($outcome->allows()) {
            return $outcome;
        }

        $detections = $this->filterByCooldown($outcome, $botId, $chatId, $userId, $moduleSettings);
        if ($detections === []) {
            return $outcome;
        }

        try {
            $this->persistAndEnforce($outcome, $detections, $botConfig, $bypassEnforcement);
        } catch (Throwable $e) {
            $this->logger?->error('antispam: enforcement chain failed (violation stays pending)', [
                'botId' => $botId,
                'chatId' => $chatId,
                'userId' => $userId,
                'messageId' => $message->messageId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        return $outcome;
    }

    /**
     * Cooldown is rule-evaluation-level anti-flapping: repeated detections of a
     * rule within its window collapse into one.
     *
     * @param  array<string, mixed>  $moduleSettings
     * @return list<AntiSpamDetection>
     */
    private function filterByCooldown(
        EvaluationOutcome $outcome,
        string $botId,
        int $chatId,
        int $userId,
        array $moduleSettings,
    ): array {
        $cooldownMap = (array) ($moduleSettings['rule_cooldowns'] ?? []);
        $kept = [];

        foreach ($outcome->score->detections as $detection) {
            $seconds = (int) ($cooldownMap[$detection->ruleId] ?? 0);
            if ($seconds > 0 && ! $this->cooldown->claim($botId, $chatId, $userId, $detection->ruleId, $seconds)) {
                continue;
            }
            $kept[] = $detection;
        }

        return $kept;
    }

    /** @param  list<AntiSpamDetection>  $detections */
    private function persistAndEnforce(
        EvaluationOutcome $outcome,
        array $detections,
        TgBotConfig $botConfig,
        bool $bypassEnforcement,
    ): void {
        $cappedScore = new AggregatedScore(
            total: $outcome->verdict->score,
            globalCap: $outcome->plan->globalCap,
            groupBreakdown: $outcome->score->groupBreakdown,
            detections: $detections,
        );

        $snapshot = new EvaluationSnapshot(
            policyVersion: $outcome->plan->policyVersion,
            riskVersion: $outcome->risk?->riskVersion ?? 'unknown',
            rulesetVersion: $outcome->plan->rulesetVersion,
            matchedRules: array_map(
                static fn (AntiSpamDetection $d): array => [
                    'ruleId' => $d->ruleId,
                    'score' => $d->score,
                    'severity' => $d->severity->value,
                    'kind' => $d->kind->value,
                    'group' => $d->group,
                    'reason' => $d->reason,
                ],
                $detections,
            ),
            groupBreakdown: $cappedScore->groupBreakdown,
            score: $cappedScore->total,
            verdict: ['action' => $outcome->verdict->action->value],
        );

        $context = $outcome->context;

        // bypass enforcement: observation happened above, no actions applied
        if ($bypassEnforcement) {
            return;
        }

        [$violation] = $this->recorder->record(
            botId: $botConfig->botId,
            chatId: $context->chat->chatId,
            userId: $context->user->userId,
            message: $context->message,
            score: $cappedScore,
            verdict: $outcome->verdict,
            snapshot: $snapshot,
            risk: $outcome->risk,
        );

        if ($outcome->verdict->action->value !== 'warn') {
            $this->strikes->registerStrike($violation);
        }

        $this->executor->execute($violation, $botConfig);

        // CAPTCHA soft-threshold trigger: warn verdict + captcha enabled → challenge
        if ($outcome->verdict->action->value === 'warn') {
            $this->captcha?->challengeUser(
                $botConfig->botId,
                $context->chat->chatId,
                $context->user->userId,
                $botConfig,
            );
        }

        try {
            $this->bumpStats($botConfig->botId, $context->chat->chatId, $detections);
        } catch (Throwable) {
            // stats are best-effort
        }
    }

    /** @param  list<AntiSpamDetection>  $detections */
    private function bumpStats(string $botId, int $chatId, array $detections): void
    {
        $groups = array_unique(array_map(static fn (AntiSpamDetection $d): string => $d->group, $detections));
        foreach ($groups === [] ? [null] : $groups as $group) {
            $stat = AntispamStat::query()->firstOrNew([
                'stat_date' => now()->toDateString(),
                'bot_id' => $botId,
                'chat_id' => $chatId,
                'group_id' => $group,
            ]);
            $stat->violations += 1;
            $stat->detections += count($detections);
            $stat->save();
        }
    }
}
