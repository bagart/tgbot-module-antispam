<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Engine;

use BAGArt\TelegramBotAntispam\Models\AntispamRuleModel;

/**
 * Converts antispam_rules rows (platform defaults + bot-scoped) into
 * PolicyCompiler settings arrays. Bot-scoped rows win over platform rows.
 * Cached by PolicyCompiler's rulesetVersion — no per-webhook SQL after compile.
 */
final readonly class DbRuleOverrides
{
    /**
     * @return array<string, mixed> partial settings array
     */
    public function forBot(string $botId): array
    {
        $rows = AntispamRuleModel::query()
            ->where(fn ($q) => $q->whereNull('bot_id')->orWhere('bot_id', $botId))
            ->orderBy('bot_id') // NULL (platform) first, bot-specific wins on collision
            ->get();

        $settings = [
            'rule_scores' => [],
            'rule_severities' => [],
            'rule_kinds' => [],
            'rule_params' => [],
            'rule_cooldowns' => [],
            'disabled_rules' => [],
            'db_rules' => [],
        ];

        foreach ($rows as $row) {
            // name doubles as the built-in rule id key; config carries typed parameters
            $ruleId = $row->name;
            $config = is_array($row->config) ? $row->config : [];

            if (! $row->is_active) {
                $settings['disabled_rules'][$ruleId] = false;

                continue;
            }

            $settings['rule_scores'][$ruleId] = $row->score_weight;
            $settings['rule_severities'][$ruleId] = $row->severity;
            $settings['rule_kinds'][$ruleId] = $row->kind;

            if ($row->cooldown_seconds !== null) {
                $settings['rule_cooldowns'][$ruleId] = $row->cooldown_seconds;
            }
            if (isset($config['param'])) {
                $settings['rule_params'][$ruleId] = (int) $config['param'];
            }

            $settings['db_rules'][$ruleId] = [
                'group' => $row->group_id,
                'type' => $row->type,
                'priority' => $row->priority,
                'config' => $config,
            ];
        }

        return $settings;
    }
}
