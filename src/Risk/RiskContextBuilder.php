<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Risk;

use BAGArt\TelegramBotAntispam\Domain\BehaviorContext;
use BAGArt\TelegramBotAntispam\Domain\RiskContext;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Builds the deterministic risk level from hot signals (counters), cold
 * aggregates (cached user_strikes row) and the P3.8 extra signals (honeypot
 * hit, cross-bot reputation, registration attributes). Pure function of its
 * inputs.
 */
final readonly class RiskContextBuilder
{
    public const string VERSION = 'antispam.risk.v2';

    private const string CACHE_PREFIX = 'antispam:risk:';

    private const string REPUTATION_CACHE_PREFIX = 'antispam:reputation:';

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
        ?RiskSignals $signals = null,
    ): RiskContext {
        $signals ??= new RiskSignals();
        $previousViolations = $coldAggregate['total_strikes'] ?? $this->cachedViolations($botId, $chatId, $userId);
        $previousMessages = max($behavior->messages1h, $behavior->messages5m);

        return new RiskContext(
            level: $this->level($previousViolations, $previousMessages, $signals),
            accountAgeDays: null, // Bot API does not provide it reliably; future signal
            chatMemberAgeDays: null, // requires getChatMember — never called on the webhook path
            previousMessages: $previousMessages,
            previousViolations: $previousViolations,
            riskVersion: self::VERSION,
        );
    }

    /** Deterministic factor → level mapping (versioned). */
    private function level(int $previousViolations, int $previousMessages, RiskSignals $signals): string
    {
        // Honeypot is an instant hard signal: straight to HIGH.
        if ($signals->honeypotHit) {
            return RiskContext::LEVEL_HIGH;
        }

        $score = 0;
        if ($previousViolations >= 3) {
            $score += 2;
        } elseif ($previousViolations >= 1) {
            ++$score;
        }
        if ($previousMessages > 20) {
            --$score;
        }

        // Reputation: banned by several bots of the platform → likely relay account.
        if ($signals->reputationBans >= 2) {
            $score += 2;
        } elseif ($signals->reputationBans === 1) {
            ++$score;
        }

        // Registration attributes available from the Bot API. A missing
        // username plus forwarding others' content is the classic
        // "account-less forwarder" spam profile.
        if (! $signals->hasUsername) {
            ++$score;
            if ($signals->isForwarded) {
                ++$score;
            }
        }
        // is_premium is tracked but deliberately not scored: too weak/noisy.

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

    /**
     * Cross-bot reputation (P3.7 feed as input): number of distinct bots that
     * published a ban for this user. Cached; failures degrade to zero.
     */
    public function reputationBans(int $userId): int
    {
        $key = self::REPUTATION_CACHE_PREFIX.(string) $userId;

        try {
            $cached = $this->cache->get($key);
            if (is_int($cached)) {
                return $cached;
            }

            $count = \BAGArt\TelegramBotAntispam\Models\AntispamBlocklistFeed::query()
                ->where('user_id', $userId)
                ->distinct()
                ->count('source_bot_id');

            $this->cache->set($key, $count, max($this->ttlSeconds, 300));

            return $count;
        } catch (Throwable) {
            return 0;
        }
    }
}
