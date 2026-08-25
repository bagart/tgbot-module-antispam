<?php

namespace BAGArt\TelegramBotAntispam\Http\Controllers;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBotAntispam\Moderation\AntispamModerationService;
use BAGArt\TelegramBotAntispam\Models\AntispamStrikeEvent;
use BAGArt\TelegramBotAntispam\Models\AntispamViolation;
use BAGArt\TelegramBotAntispam\Rules\RuleRegistry;
use BAGArt\TelegramBotManagement\Models\TgBot;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AntispamViolationsController
{
    public function __construct(
        private readonly AntispamModerationService $moderation,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'bot_id' => ['nullable', 'string', 'max:20'],
            'chat_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            // Moderation queue defaults to pending; "all" disables the filter
            'status' => ['nullable', Rule::in([...AntispamViolation::STATUSES, 'all'])],
            'group' => ['nullable', 'string', Rule::in(array_keys(RuleRegistry::GROUPS))],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $query = $this->filteredQuery($filters);
        $effectiveStatus = $filters['status'] ?? AntispamViolation::STATUS_PENDING;

        return Inertia::render('antispam/violations', [
            'violations' => $query
                ->when($effectiveStatus !== 'all', fn (Builder $q) => $q->where('status', $effectiveStatus))
                ->latest()
                ->paginate(20)
                ->withQueryString()
                ->through(fn (AntispamViolation $v): array => [
                    'id' => (string) $v->id,
                    'botId' => (string) $v->bot_id,
                    'chatId' => (int) $v->chat_id,
                    'userId' => (int) $v->user_id,
                    'messageId' => (int) $v->message_id,
                    'messageText' => (string) ($v->message_snapshot['text'] ?? $v->message_snapshot['caption'] ?? ''),
                    'matchedRules' => (array) $v->matched_rules,
                    'groupBreakdown' => (array) $v->group_breakdown,
                    'score' => (int) $v->score,
                    'enforcementAction' => (string) $v->enforcement_action,
                    'status' => (string) $v->status,
                    'evaluationSnapshot' => (array) $v->evaluation_snapshot,
                    'riskContext' => $v->risk_context,
                    'createdAt' => (string) $v->created_at,
                ]),
            'filters' => [...$filters, 'status' => $effectiveStatus],
            'bots' => TgBot::query()->orderBy('bot_id')->get(['bot_id']),
            'statuses' => ['all', ...AntispamViolation::STATUSES],
            'groups' => array_keys(RuleRegistry::GROUPS),
        ]);
    }

    public function action(Request $request, string $violationId): JsonResponse
    {
        $data = $this->validateAction($request);

        $violation = AntispamViolation::query()->find($violationId);
        if ($violation === null) {
            throw new NotFoundHttpException("Violation [{$violationId}] not found.");
        }

        [$done, $error] = $this->act($violation, $data['action']);
        if (! $done) {
            return response()->json(['error' => $error], 409);
        }

        return response()->json([
            'violationId' => (string) $violation->id,
            'status' => (string) $violation->refresh()->status,
            'enforcementAction' => (string) $violation->enforcement_action,
        ]);
    }

    public function bulkAction(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['apply', 'overturn', 'escalate'])],
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['required', 'string', 'distinct'],
        ]);

        $violations = AntispamViolation::query()->whereIn('id', $data['ids'])->get();
        $botConfigs = [];
        $updated = [];
        $skipped = [];

        foreach ($violations as $violation) {
            $botId = (string) $violation->bot_id;
            $botConfigs[$botId] ??= $this->botConfigFor($botId);

            if ($botConfigs[$botId] === null) {
                $skipped[] = ['id' => (string) $violation->id, 'reason' => "Bot [{$botId}] has no token on record."];

                continue;
            }

            [$done, $error] = $this->act($violation, $data['action']);
            if ($done) {
                $updated[] = ['id' => (string) $violation->id, 'status' => (string) $violation->refresh()->status];
            } else {
                $skipped[] = ['id' => (string) $violation->id, 'reason' => $error];
            }
        }

        foreach (array_diff($data['ids'], $violations->modelKeys()) as $missingId) {
            $skipped[] = ['id' => $missingId, 'reason' => 'Violation not found.'];
        }

        return response()->json(['updated' => $updated, 'skipped' => $skipped]);
    }

    public function history(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bot_id' => ['required', 'string', 'max:20'],
            'user_id' => ['required', 'integer'],
        ]);

        $violations = AntispamViolation::query()
            ->where('bot_id', $data['bot_id'])
            ->where('user_id', $data['user_id'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $strikes = AntispamStrikeEvent::query()
            ->where('bot_id', $data['bot_id'])
            ->where('user_id', $data['user_id'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $events = [
            ...$violations->map(fn (AntispamViolation $v): array => [
                'type' => 'violation',
                'id' => (string) $v->id,
                'chatId' => (int) $v->chat_id,
                'messageId' => (int) $v->message_id,
                'score' => (int) $v->score,
                'enforcementAction' => (string) $v->enforcement_action,
                'status' => (string) $v->status,
                'rules' => array_column((array) $v->matched_rules, 'ruleId'),
                'at' => $v->created_at->toISOString(),
            ]),
            ...$strikes->map(fn (AntispamStrikeEvent $s): array => [
                'type' => 'strike',
                'id' => (string) $s->id,
                'chatId' => (int) $s->chat_id,
                'consequence' => (string) $s->strike_consequence,
                'active' => (bool) $s->active,
                'expiresAt' => $s->expired_at?->toISOString(),
                'violationId' => (string) $s->violation_id,
                'at' => $s->created_at->toISOString(),
            ]),
        ];

        usort($events, static fn (array $a, array $b): int => strcmp((string) $a['at'], (string) $b['at']));

        return response()->json(['botId' => $data['bot_id'], 'userId' => (int) $data['user_id'], 'events' => $events]);
    }

    /**
     * @return array{0: bool, 1: string} transitioned flag + error message when false
     */
    private function act(AntispamViolation $violation, string $action): array
    {
        $botConfig = $this->botConfigFor((string) $violation->bot_id);
        if ($botConfig === null) {
            return [false, "Bot [{$violation->bot_id}] has no token on record."];
        }

        $transitioned = match ($action) {
            'apply' => $this->moderation->applyViolation($violation, $botConfig),
            'overturn' => $this->moderation->overturn($violation, $botConfig),
            'escalate' => $this->moderation->escalate($violation, $botConfig),
        };

        if (! $transitioned) {
            $pastTense = ['apply' => 'applied', 'overturn' => 'overturned', 'escalate' => 'escalated'][$action];

            return [false, "Violation is in status [{$violation->status}] and cannot be {$pastTense}."];
        }

        return [true, ''];
    }

    private function botConfigFor(string $botId): ?TgBotConfig
    {
        $bot = TgBot::query()->where('bot_id', $botId)->first();

        return $bot === null ? null : new TgBotConfig(token: $bot->token, botId: (string) $bot->bot_id);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filteredQuery(array $filters): Builder
    {
        return AntispamViolation::query()
            ->when($filters['bot_id'] ?? null, fn (Builder $q, string $v) => $q->where('bot_id', $v))
            ->when($filters['chat_id'] ?? null, fn (Builder $q, int $v) => $q->where('chat_id', $v))
            ->when($filters['user_id'] ?? null, fn (Builder $q, int $v) => $q->where('user_id', $v))
            ->when(
                $filters['group'] ?? null,
                // Portable JSON membership check: matched_rules elements embed "group": "<id>"
                fn (Builder $q, string $v) => $q->where(
                    'matched_rules',
                    'like',
                    '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], '"group":"'.$v.'"').'%',
                ),
            )
            ->when($filters['date_from'] ?? null, fn (Builder $q, string $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn (Builder $q, string $v) => $q->whereDate('created_at', '<=', $v));
    }

    /**
     * @return array{action: string}
     */
    private function validateAction(Request $request): array
    {
        /** @var array{action: string} $validated */
        $validated = $request->validate([
            'action' => ['required', Rule::in(['apply', 'overturn', 'escalate'])],
        ]);

        return $validated;
    }
}
