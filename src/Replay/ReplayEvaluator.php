<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Replay;

use BAGArt\TelegramBotAntispam\Domain\AggregatedScore;
use BAGArt\TelegramBotAntispam\Domain\AntiSpamDetection;
use BAGArt\TelegramBotAntispam\Domain\DetectionKind;
use BAGArt\TelegramBotAntispam\Domain\DetectionSeverity;
use BAGArt\TelegramBotAntispam\Domain\EnforcementAction;
use BAGArt\TelegramBotAntispam\Models\AntispamViolation;
use BAGArt\TelegramBotAntispam\Engine\PolicyEvaluator;

/**
 * Replays a stored violation against a (new) plan: same detections, new
 * policy. Enables old-vs-new comparison, false-positive debugging and
 * regression analysis.
 */
final readonly class ReplayEvaluator
{
    public function __construct(
        private PolicyEvaluator $policyEvaluator,
    ) {
    }

    public function replay(AntispamViolation $violation, \BAGArt\TelegramBotAntispam\Domain\EvaluationPlan $plan): ReplayComparison
    {
        $detections = array_map(
            static fn (array $rule): AntiSpamDetection => new AntiSpamDetection(
                ruleId: (string) ($rule['ruleId'] ?? 'unknown'),
                score: (int) ($rule['score'] ?? 0),
                severity: DetectionSeverity::from((string) ($rule['severity'] ?? 'low')),
                kind: DetectionKind::from((string) ($rule['kind'] ?? 'soft')),
                group: (string) ($rule['group'] ?? 'behavior'),
                reason: (string) ($rule['reason'] ?? ''),
            ),
            array_values((array) $violation->matched_rules),
        );

        $score = new AggregatedScore(
            total: (int) $violation->score,
            globalCap: $plan->globalCap,
            groupBreakdown: (array) $violation->group_breakdown,
            detections: $detections,
        );

        $newVerdict = $this->policyEvaluator->evaluate($score, null, $plan);
        $oldAction = EnforcementAction::fromName((string) ($violation->verdict['action'] ?? 'warn'));

        return new ReplayComparison(
            violationId: (string) $violation->id,
            oldAction: $oldAction,
            newAction: $newVerdict->action,
            oldScore: (int) $violation->score,
            newVerdict: $newVerdict,
        );
    }
}
