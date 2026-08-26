<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Engine;

use BAGArt\TelegramBotAntispam\Models\AntispamRuleModel;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Converts antispam_rules rows (platform defaults + bot-scoped) into
 * PolicyCompiler settings arrays. Bot-scoped rows win over platform rows.
 *
 * Cached per bot behind a global version key (0 SQL on steady-state webhooks):
 * any rule mutation bumps the version, which orphans every bot's cached map
 * without needing to enumerate bots. TTL bounds staleness when the cache
 * itself degrades.
 */
final readonly class DbRuleOverrides
{
    private const string VERSION_KEY = 'antispam:dbrules:ver';
    private const string MAP_PREFIX = 'antispam:dbrules:map:';

    public function __construct(
        private CacheInterface $cache,
        private int $ttlSeconds = 300,
    ) {
    }

    /**
     * @return array<string, mixed> partial settings array
     */
    public function forBot(string $botId): array
    {
        $mapKey = self::MAP_PREFIX.$botId.':'.$this->version();

        try {
            $cached = $this->cache->get($mapKey);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable) {
            // cache unavailable — load through
        }

        $settings = $this->load($botId);

        try {
            $this->cache->set($mapKey, $settings, $this->ttlSeconds);
        } catch (Throwable) {
            // non-fatal
        }

        return $settings;
    }

    /** Orphans all cached bot maps (rule create/update/delete). */
    public function invalidate(): void
    {
        try {
            $this->cache->delete(self::VERSION_KEY);
        } catch (Throwable) {
            // TTL bounds staleness
        }
    }

    private function version(): string
    {
        try {
            $version = $this->cache->get(self::VERSION_KEY);
            if (is_string($version) && $version !== '') {
                return $version;
            }

            $fresh = bin2hex(random_bytes(4));
            $this->cache->set(self::VERSION_KEY, $fresh, $this->ttlSeconds);

            return $fresh;
        } catch (Throwable) {
            // unique per-request fallback keeps reads correct without cache
            return bin2hex(random_bytes(4));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $botId): array
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

    /**
     * Merges DB overrides into module settings KEY-WISE: within list/map keys
     * (rule_scores, disabled_rules, …) DB wins per rule id, while chat-level
     * entries for rules absent from the DB survive. A plain array_merge would
     * let empty DB sections wipe chat settings wholesale.
     *
     * @param  array<string, mixed>  $moduleSettings
     * @param  array<string, mixed>  $dbSettings
     * @return array<string, mixed>
     */
    public static function mergeInto(array $moduleSettings, array $dbSettings): array
    {
        $merged = $moduleSettings;

        foreach ($dbSettings as $key => $value) {
            if (is_array($value)) {
                $merged[$key] = array_merge((array) ($merged[$key] ?? []), $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }
}
