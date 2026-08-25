<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\UserList;

use BAGArt\TelegramBotAntispam\Models\AntispamUserListEntry;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Gating lists. Semantics (fixed by the RFC):
 *   whitelist  → bypass module (counters are NOT updated, rules not evaluated)
 *   blacklist  → bypass enforcement (observation continues, no actions applied)
 * Cached per (bot, chat, list) with TTL; 0 SQL on the clean-message path.
 */
final readonly class UserListManager
{
    private const string CACHE_PREFIX = 'antispam:lists:';

    public function __construct(
        private CacheInterface $cache,
        private int $ttlSeconds = 300,
    ) {
    }

    public function isWhitelisted(string $botId, int $chatId, int $userId): bool
    {
        return in_array($userId, $this->listMembers($botId, $chatId, 'whitelist'), true);
    }

    public function isBlacklisted(string $botId, int $chatId, int $userId): bool
    {
        return in_array($userId, $this->listMembers($botId, $chatId, 'blacklist'), true);
    }

    /** @return list<int> */
    private function listMembers(string $botId, int $chatId, string $listType): array
    {
        $key = self::CACHE_PREFIX.$botId.':'.$chatId.':'.$listType;

        try {
            $cached = $this->cache->get($key);
            if (is_array($cached)) {
                return array_map('intval', $cached);
            }

            $members = AntispamUserListEntry::query()
                ->where('bot_id', $botId)
                ->where('chat_id', $chatId)
                ->where('list_type', $listType)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->pluck('user_id')
                ->all();

            $this->cache->set($key, $members, $this->ttlSeconds);

            return array_map('intval', $members);
        } catch (Throwable) {
            // Storage failure → no gating (fail-open), content rules still apply
            return [];
        }
    }

    /**
     * Creates/refreshes a whitelist entry (used by the CAPTCHA pass flow).
     * Throws on storage failure — callers decide the failure policy.
     */
    public function addWhitelistEntry(
        string $botId,
        int $chatId,
        int $userId,
        string $reason,
        ?\DateTimeInterface $expiresAt,
    ): void {
        AntispamUserListEntry::query()->updateOrCreate([
            'bot_id' => $botId,
            'chat_id' => $chatId,
            'user_id' => $userId,
            'list_type' => 'whitelist',
        ], [
            'reason' => $reason,
            'expires_at' => $expiresAt,
            'created_by' => 'antispam:captcha',
        ]);

        $this->refresh($botId, $chatId);
    }

    public function refresh(string $botId, int $chatId): void
    {
        foreach (['whitelist', 'blacklist'] as $listType) {
            try {
                $this->cache->delete(self::CACHE_PREFIX.$botId.':'.$chatId.':'.$listType);
            } catch (Throwable) {
                // TTL bounds staleness
            }
        }
    }
}
