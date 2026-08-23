<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Domain;

/**
 * Compiled policy: settings merged once into an immutable plan, cached per
 * (bot, chat, rulesetVersion). The webhook path never merges settings.
 */
final readonly class EvaluationPlan
{
    /**
     * @param  list<RuleGroup>  $groupCaps
     * @param  array<string, int>  $floodWindows  window key → message count (burst/short/medium/long)
     * @param  array<string, int>  $windowSeconds  window key → seconds
     * @param  array<string, int>  $ruleScores  rule id → base score
     * @param  array<string, string>  $ruleSeverities  rule id → severity value
     * @param  array<string, string>  $ruleKinds  rule id → kind value
     * @param  array<string, bool>  $enabledRules  rule id → on/off
     * @param  array<string, int>  $ruleParams  rule id → single numeric parameter (thresholds beyond flood windows)
     */
    public function __construct(
        public string $policyVersion,
        public string $rulesetVersion,
        public int $warnScore,
        public int $restrictScore,
        public int $banScore,
        public int $globalCap,
        public array $groupCaps = [],
        public SeverityActionMap $severityActions = new SeverityActionMap(),
        public RiskTransitions $riskTransitions = new RiskTransitions(),
        public array $ruleScores = [],
        public array $ruleSeverities = [],
        public array $ruleKinds = [],
        public array $enabledRules = [],
        public array $floodWindows = [
            'burst' => 5,
            'short' => 15,
            'medium' => 40,
            'long' => 200,
        ],
        public array $windowSeconds = [
            'burst' => 5,
            'short' => 30,
            'medium' => 300,
            'long' => 3600,
        ],
        public array $ruleParams = [],
    ) {
    }

    /** @return array{warn: int, restrict: int, ban: int} */
    public function thresholds(): array
    {
        return ['warn' => $this->warnScore, 'restrict' => $this->restrictScore, 'ban' => $this->banScore];
    }

    public function isEnabled(string $ruleId): bool
    {
        return $this->enabledRules[$ruleId] ?? true;
    }

    public function scoreOf(string $ruleId, int $default): int
    {
        return $this->ruleScores[$ruleId] ?? $default;
    }

    public function severityOf(string $ruleId, DetectionSeverity $default): DetectionSeverity
    {
        $severity = $this->ruleSeverities[$ruleId] ?? null;

        return $severity === null ? $default : DetectionSeverity::from($severity);
    }

    public function kindOf(string $ruleId, DetectionKind $default): DetectionKind
    {
        $kind = $this->ruleKinds[$ruleId] ?? null;

        return $kind === null ? $default : DetectionKind::from($kind);
    }

    public function paramOf(string $ruleId, int $default): int
    {
        return $this->ruleParams[$ruleId] ?? $default;
    }
}
