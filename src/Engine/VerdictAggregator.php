<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Engine;

use BAGArt\TelegramBotAntispam\Domain\AggregatedScore;
use BAGArt\TelegramBotAntispam\Domain\EvaluationPlan;

/** Per-rule contributions → per-group caps → global cap. Pure. */
final readonly class VerdictAggregator
{
    public function aggregate(array $detections, EvaluationPlan $plan): AggregatedScore
    {
        $capByGroup = [];
        foreach ($plan->groupCaps as $group) {
            $capByGroup[$group->id] = $group->cap;
        }

        $sums = [];
        foreach ($detections as $detection) {
            $sums[$detection->group] = ($sums[$detection->group] ?? 0) + $detection->score;
        }

        $breakdown = [];
        $total = 0;
        foreach ($sums as $groupId => $sum) {
            $cap = $capByGroup[$groupId] ?? $plan->globalCap;
            $contribution = min($sum, $cap);
            $breakdown[$groupId] = ['contribution' => $contribution, 'cap' => $cap];
            $total += $contribution;
        }

        return new AggregatedScore(
            total: min($total, $plan->globalCap),
            globalCap: $plan->globalCap,
            groupBreakdown: $breakdown,
            detections: $detections,
        );
    }
}
