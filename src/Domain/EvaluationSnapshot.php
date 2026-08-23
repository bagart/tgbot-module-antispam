<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Domain;

/**
 * Stored with every violation: exact policy/risk/ruleset versions that
 * produced the verdict. Enables replay, regression analysis, dry-run comparison.
 */
final readonly class EvaluationSnapshot
{
    /**
     * @param  list<array<string, mixed>>  $matchedRules  serialized detections
     * @param  array<string, array{contribution: int, cap: int}>  $groupBreakdown
     * @param  array<string, mixed>  $verdict
     */
    public function __construct(
        public string $policyVersion,
        public string $riskVersion,
        public string $rulesetVersion,
        public array $matchedRules,
        public array $groupBreakdown,
        public int $score,
        public array $verdict,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'policyVersion' => $this->policyVersion,
            'riskVersion' => $this->riskVersion,
            'rulesetVersion' => $this->rulesetVersion,
            'matchedRules' => $this->matchedRules,
            'groupBreakdown' => $this->groupBreakdown,
            'score' => $this->score,
            'verdict' => $this->verdict,
        ];
    }
}
