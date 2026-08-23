<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Engine;

use BAGArt\TelegramBotAntispam\Domain\AggregatedScore;
use BAGArt\TelegramBotAntispam\Domain\AntiSpamVerdict;
use BAGArt\TelegramBotAntispam\Domain\DetectionKind;
use BAGArt\TelegramBotAntispam\Domain\EnforcementAction;
use BAGArt\TelegramBotAntispam\Domain\EvaluationPlan;
use BAGArt\TelegramBotAntispam\Domain\RiskContext;

/**
 * Pure policy evaluation in the fixed precedence order:
 * hard minimum → score thresholds → risk transitions → final verdict.
 * Risk can raise an action but never below the hard minimum.
 */
final readonly class PolicyEvaluator
{
    public function evaluate(AggregatedScore $score, ?RiskContext $risk, EvaluationPlan $plan): AntiSpamVerdict
    {
        $hardMinimum = $this->hardMinimum($score, $plan);
        $action = $this->actionFromScore($score->total, $plan);
        $reason = $this->scoreReason($score->total, $plan);

        if ($hardMinimum !== null) {
            $action = $action === null
                ? $hardMinimum
                : $action->strongest($hardMinimum);
            $reason = 'hard minimum ('.$hardMinimum->value.') + '.$reason;
        }

        if ($action !== null && $risk !== null) {
            $transition = $plan->riskTransitions->forLevel($risk->level);
            if ($transition !== null && $score->total >= $transition['at_score']) {
                $riskAction = EnforcementAction::fromName($transition['action']);
                $action = $action->strongest($riskAction);
                $reason .= '; risk='.$risk->level.' → '.$riskAction->value;
            }
        }

        // Nothing triggered: allow (warn action with sub-warn score = no violation)
        return new AntiSpamVerdict(
            action: $action ?? EnforcementAction::Warn,
            score: $score->total,
            reason: $reason === '' ? 'no detections' : $reason,
            policyVersion: $plan->policyVersion,
            thresholds: $plan->thresholds(),
            matchedRules: array_map(
                static fn ($d): string => $d->ruleId,
                $score->detections,
            ),
        );
    }

    private function hardMinimum(AggregatedScore $score, EvaluationPlan $plan): ?EnforcementAction
    {
        $minimum = null;
        foreach ($score->detections as $detection) {
            if ($detection->kind !== DetectionKind::Hard) {
                continue;
            }
            $required = $plan->severityActions->minimumFor($detection->severity);
            if ($required !== null && ($minimum === null || $required->isAtLeast($minimum))) {
                $minimum = $required;
            }
        }

        return $minimum;
    }

    private function actionFromScore(int $total, EvaluationPlan $plan): ?EnforcementAction
    {
        return match (true) {
            $total >= $plan->banScore => EnforcementAction::Ban,
            $total >= $plan->restrictScore => EnforcementAction::Restrict,
            $total >= $plan->warnScore => EnforcementAction::Warn,
            default => null,
        };
    }

    private function scoreReason(int $total, EvaluationPlan $plan): string
    {
        return match (true) {
            $total >= $plan->banScore => "score {$total} >= ban threshold",
            $total >= $plan->restrictScore => "score {$total} >= restrict threshold",
            $total >= $plan->warnScore => "score {$total} >= warn threshold",
            default => '',
        };
    }
}
