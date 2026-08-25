<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Captcha;

use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Cache-backed pending-challenge store. One challenge per (bot, chat, user);
 * issue() is idempotent while a challenge is pending, consume() enforces the
 * single-use token contract (second click finds nothing).
 */
final readonly class CaptchaStore
{
    private const string PREFIX = 'antispam:captcha:';

    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function pending(string $botId, int $chatId, int $userId): ?CaptchaChallenge
    {
        try {
            $payload = $this->cache->get($this->key($botId, $chatId, $userId));

            return is_array($payload) ? CaptchaChallenge::fromArray($payload) : null;
        } catch (Throwable) {
            // store unavailable — treat as "no challenge pending" (fail-open)
            return null;
        }
    }

    /**
     * Returns [token, created]. Reuses an active challenge (idempotent issue):
     * created=false means a challenge was already pending for the user.
     *
     * @return array{string, bool}
     */
    public function issue(string $botId, int $chatId, int $userId, int $ttlSeconds): array
    {
        $existing = $this->pending($botId, $chatId, $userId);
        if ($existing !== null) {
            return [$existing->token, false];
        }

        $token = bin2hex(random_bytes(6));
        $challenge = new CaptchaChallenge(
            token: $token,
            chatId: $chatId,
            userId: $userId,
            issuedAt: time(),
        );

        try {
            $this->cache->set($this->key($botId, $chatId, $userId), $challenge->toArray(), $ttlSeconds + 60);
        } catch (Throwable) {
            // store unavailable — no persistence; the trigger path still restricted
            // the user, and restriction auto-expires via untilDate.
        }

        return [$token, true];
    }

    /** Single-use consume: true only when token matches the stored challenge. */
    public function consume(string $botId, int $chatId, int $userId, string $token): bool
    {
        $key = $this->key($botId, $chatId, $userId);

        try {
            $payload = $this->cache->get($key);
            if (! is_array($payload)) {
                return false;
            }

            $this->cache->delete($key);

            return hash_equals((string) ($payload['token'] ?? ''), $token);
        } catch (Throwable) {
            return false;
        }
    }

    public function forget(string $botId, int $chatId, int $userId): void
    {
        try {
            $this->cache->delete($this->key($botId, $chatId, $userId));
        } catch (Throwable) {
            // TTL bounds staleness
        }
    }

    private function key(string $botId, int $chatId, int $userId): string
    {
        return self::PREFIX.$botId.':'.$chatId.':'.$userId;
    }
}
