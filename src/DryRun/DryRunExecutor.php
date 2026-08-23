<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\DryRun;

use BAGArt\TelegramBotAntispam\Domain\AntiSpamVerdict;
use BAGArt\TelegramBotAntispam\Domain\EvaluationPlan;

/** Full breakdown of one evaluation without any side effects. */
final readonly class DryRunReport
{
    /**
     * @param  list<array<string, mixed>>  $matchedRules
     * @param  array<string, array{contribution: int, cap: int}>  $groupBreakdown
     */
    public function __construct(
        public string $policyVersion,
        public array $matchedRules,
        public array $groupBreakdown,
        public int $score,
        public int $globalCap,
        public AntiSpamVerdict $verdict,
        public EvaluationPlan $plan,
    ) {
    }

    /** @return list<string> */
    public function toLines(): array
    {
        $lines = ["policy: {$this->policyVersion}, score {$this->score}/{$this->globalCap}"];
        foreach ($this->matchedRules as $rule) {
            $lines[] = sprintf(
                '  · %s [%s/%s] +%d — %s',
                (string) $rule['ruleId'],
                (string) $rule['severity'],
                (string) $rule['kind'],
                (int) $rule['score'],
                (string) $rule['reason'],
            );
        }
        foreach ($this->groupBreakdown as $group => $info) {
            $lines[] = sprintf('  Σ %s: %d/cap %d', $group, $info['contribution'], $info['cap']);
        }
        $lines[] = "verdict: {$this->verdict->action->value} ({$this->verdict->reason})";

        return $lines;
    }
}

/**
 * Runs the same pure pipeline as the webhook path but with an empty behavior
 * context and no persistence/enforcement. Used by the admin Test button and
 * `/antispam test`.
 */
final readonly class DryRunExecutor
{
    public function __construct(
        private \BAGArt\TelegramBotAntispam\Engine\AntispamEvaluator $evaluator,
    ) {
    }

    public function run(
        \BAGArt\TelegramBotAntispam\Domain\AntispamMessageContext $context,
        EvaluationPlan $plan,
        ?\BAGArt\TelegramBotAntispam\Domain\RiskContext $risk = null,
    ): DryRunReport {
        $outcome = $this->evaluator->evaluate($context, $plan, $risk);

        return new DryRunReport(
            policyVersion: $plan->policyVersion,
            matchedRules: array_map(
                static fn (\BAGArt\TelegramBotAntispam\Domain\AntiSpamDetection $d): array => [
                    'ruleId' => $d->ruleId,
                    'score' => $d->score,
                    'severity' => $d->severity->value,
                    'kind' => $d->kind->value,
                    'reason' => $d->reason,
                ],
                $outcome->detections,
            ),
            groupBreakdown: $outcome->score->groupBreakdown,
            score: $outcome->verdict->score,
            globalCap: $plan->globalCap,
            verdict: $outcome->verdict,
            plan: $plan,
        );
    }
}
