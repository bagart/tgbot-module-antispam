<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Risk;

use BAGArt\TelegramBotAntispam\Domain\BehaviorContext;
use BAGArt\TelegramBotAntispam\Domain\RiskContext;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Builds the deterministic risk level from hot signals (counters) and cold
 * aggregates (cached user_strikes row). Pure function of its inputs.
 */
final readonly class RiskContextBuilder
{
    public const string VERSION = 'antispam.risk.v1';

    private const string CACHE_PREFIX = 'antispam:risk:';

    public function __construct(
        private CacheInterface $cache,
        private int $ttlSeconds = 60,
    ) {
    }

    /**
     * @param  array{total_strikes?: int, active_strikes?: int}|null  $coldAggregate
     */
    public function build(
        string $botId,
        int $chatId,
        int $userId,
        BehaviorContext $behavior,
        ?array $coldAggregate = null,
    ): RiskContext {
        $previousViolations = $coldAggregate['total_strikes'] ?? $this->cachedViolations($botId, $chatId, $userId);
        $previousMessages = max($behavior->messages1h, $behavior->messages5m);

        return new RiskContext(
            level: $this->level($previousViolations, $previousMessages),
            accountAgeDays: null, // Bot API does not provide it reliably; future signal
            chatMemberAgeDays: null, // requires getChatMember — never called on the webhook path
            previousMessages: $previousMessages,
            previousViolations: $previousViolations,
            riskVersion: self::VERSION,
        );
    }

    /** Deterministic factor → level mapping (versioned). */
    private function level(int $previousViolations, int $previousMessages): string
    {
        $score = 0;
        if ($previousViolations >= 3) {
            $score += 2;
        } elseif ($previousViolations >= 1) {
            ++$score;
        }
        if ($previousMessages > 20) {
            --$score;
        }

        return match (true) {
            $score >= 2 => RiskContext::LEVEL_HIGH,
            $score === 1 => RiskContext::LEVEL_MEDIUM,
            $score <= 0 && $previousViolations >= 1 => RiskContext::LEVEL_MEDIUM,
            default => RiskContext::LEVEL_LOW,
        };
    }

    private function cachedViolations(string $botId, int $chatId, int $userId): int
    {
        $key = self::CACHE_PREFIX.$botId.':'.$chatId.':'.$userId;

        try {
            $cached = $this->cache->get($key);
            if (is_int($cached)) {
                return $cached;
            }

            $violations = \BAGArt\TelegramBotAntispam\Models\AntispamUserStrikes::query()
                ->where('bot_id', $botId)
                ->where('chat_id', $chatId)
                ->where('user_id', $userId)
                ->value('total_strikes');

            $value = (int) ($violations ?? 0);
            $this->cache->set($key, $value, $this->ttlSeconds);

            return $value;
        } catch (Throwable) {
            // cold data unavailable — neutral default keeps evaluation pure and safe
            return 0;
        }
    }
}
