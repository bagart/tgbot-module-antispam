<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Engine;

use BAGArt\TelegramBotAntispam\Domain\EvaluationPlan;
use BAGArt\TelegramBotAntispam\Domain\RuleGroup;
use BAGArt\TelegramBotAntispam\Domain\RiskTransitions;
use BAGArt\TelegramBotAntispam\Domain\SeverityActionMap;
use BAGArt\TelegramBotAntispam\Rules\RuleRegistry;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Merges module settings into an immutable EvaluationPlan once per
 * (bot, chat, ruleset version) and caches it. The webhook never re-merges.
 */
final readonly class PolicyCompiler
{
    private const string CACHE_PREFIX = 'antispam:plan:';

    public function __construct(
        private RuleRegistry $registry,
        private CacheInterface $cache,
        private int $ttlSeconds = 300,
    ) {
    }

    public function compile(string $botId, int $chatId, array $settings): EvaluationPlan
    {
        $rulesetVersion = $this->rulesetVersion($settings);
        $cacheKey = self::CACHE_PREFIX.$botId.':'.$chatId.':'.$rulesetVersion;

        try {
            $cached = $this->cache->get($cacheKey);
            if ($cached instanceof EvaluationPlan) {
                return $cached;
            }
        } catch (Throwable) {
            // cache unavailable — compile without cache
        }

        $plan = $this->buildPlan($settings);

        try {
            $this->cache->set($cacheKey, $plan, $this->ttlSeconds);
        } catch (Throwable) {
            // non-fatal
        }

        return $plan;
    }

    private function buildPlan(array $settings): EvaluationPlan
    {
        [$warnScore, $restrictScore, $banScore] = $this->thresholds($settings);
        $globalCap = (int) ($settings['global_cap'] ?? 200);

        return new EvaluationPlan(
            policyVersion: 'antispam.policy.v1',
            rulesetVersion: $this->rulesetVersion($settings),
            warnScore: $warnScore,
            restrictScore: $restrictScore,
            banScore: $banScore,
            globalCap: $globalCap,
            groupCaps: $this->groupCaps($settings, $globalCap),
            severityActions: $this->severityActions($settings),
            riskTransitions: $this->riskTransitions($settings),
            ruleScores: array_map('intval', (array) ($settings['rule_scores'] ?? [])),
            ruleSeverities: array_map('strval', (array) ($settings['rule_severities'] ?? [])),
            ruleKinds: array_map('strval', (array) ($settings['rule_kinds'] ?? [])),
            enabledRules: $this->enabledRules($settings),
            floodWindows: array_map(
                'intval',
                array_merge(
                    ['burst' => 5, 'short' => 15, 'medium' => 40, 'long' => 200],
                    (array) ($settings['flood_windows'] ?? []),
                ),
            ),
            windowSeconds: [
                'burst' => 5,
                'short' => 30,
                'medium' => 300,
                'long' => 3600,
            ],
            ruleParams: array_map('intval', (array) ($settings['rule_params'] ?? [])),
        );
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function thresholds(array $settings): array
    {
        $override = (array) ($settings['thresholds'] ?? []);
        if ($override !== []) {
            return [
                (int) ($override['warn'] ?? 40),
                (int) ($override['restrict'] ?? 80),
                (int) ($override['ban'] ?? 150),
            ];
        }

        return match ($settings['strictness'] ?? 'normal') {
            'relaxed' => [60, 120, 225],
            'strict' => [24, 48, 90],
            default => [40, 80, 150],
        };
    }

    /** @return list<RuleGroup> */
    private function groupCaps(array $settings, int $globalCap): array
    {
        $overrides = (array) ($settings['group_caps'] ?? []);
        $caps = [];

        foreach (RuleRegistry::GROUPS as $groupId => $defaultCap) {
            $caps[] = new RuleGroup(
                id: $groupId,
                cap: (int) ($overrides[$groupId] ?? min($defaultCap, $globalCap)),
            );
        }

        return $caps;
    }

    private function severityActions(array $settings): SeverityActionMap
    {
        $map = (array) ($settings['severity_actions'] ?? []);

        return new SeverityActionMap($map === [] ? [
            'high' => 'restrict',
            'critical' => 'ban',
        ] : $map);
    }

    private function riskTransitions(array $settings): RiskTransitions
    {
        $transitions = (array) ($settings['risk_transitions'] ?? []);

        return new RiskTransitions($transitions === [] ? [
            'low' => ['at_score' => 70, 'action' => 'warn'],
            'high' => ['at_score' => 70, 'action' => 'restrict'],
        ] : $transitions);
    }

    /** @return array<string, bool> rule id → enabled; custom_rules null → all on */
    private function enabledRules(array $settings): array
    {
        $enabled = [];
        foreach ($this->registry as $rule) {
            $enabled[$rule->id()] = true;
        }

        // accepts both formats: list ["rule.id"] and map ["rule.id" => false]
        foreach ((array) ($settings['disabled_rules'] ?? []) as $key => $value) {
            $ruleId = is_string($key) ? $key : $value;
            if (is_string($ruleId)) {
                $enabled[$ruleId] = false;
            }
        }

        return $enabled;
    }

    private function rulesetVersion(array $settings): string
    {
        return substr(md5(serialize($settings)), 0, 12);
    }
}
