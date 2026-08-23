<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Rules;

use BAGArt\AsyncKernel\Wrappers\ASKCacheWrapper;
use Throwable;

/**
 * Rule-level cooldown (anti-flapping): after a rule fires for a user, further
 * detections of the SAME rule are suppressed for cooldown seconds.
 * Cooldown is a property of rule evaluation — not of the violation lifecycle.
 * Atomic via cache add(); a failed claim is non-fatal (detection passes).
 */
final readonly class RuleCooldown
{
    private const string CACHE_PREFIX = 'antispam:cd:';

    public function __construct(
        private ASKCacheWrapper $cache,
    ) {
    }

    /**
     * Claims the cooldown window. true = first hit (detection counts);
     * false = suppressed repeat within the window.
     */
    public function claim(string $botId, int $chatId, int $userId, string $ruleId, int $cooldownSeconds): bool
    {
        if ($cooldownSeconds <= 0) {
            return true;
        }

        try {
            return (bool) $this->cache->add(
                self::CACHE_PREFIX.$botId.':'.$chatId.':'.$userId.':'.$ruleId,
                1,
                $cooldownSeconds,
            );
        } catch (Throwable) {
            return true;
        }
    }

    /** Non-atomic check used by dry-run (no side effects). */
    public function isActive(string $botId, int $chatId, int $userId, string $ruleId): bool
    {
        try {
            return $this->cache->has(self::CACHE_PREFIX.$botId.':'.$chatId.':'.$userId.':'.$ruleId);
        } catch (Throwable) {
            return false;
        }
    }
}
