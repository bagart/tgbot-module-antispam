<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Captcha;

/**
 * Pending challenge state cached per (bot, chat, user). Plain data only —
 * safe to serialize into the cache store.
 */
final readonly class CaptchaChallenge
{
    public function __construct(
        public string $token,
        public int $chatId,
        public int $userId,
        public int $issuedAt,
    ) {
    }

    /** @return array{token: string, chat_id: int, user_id: int, issued_at: int} */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'chat_id' => $this->chatId,
            'user_id' => $this->userId,
            'issued_at' => $this->issuedAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): ?self
    {
        if (! isset($payload['token'], $payload['chat_id'], $payload['user_id'])) {
            return null;
        }

        return new self(
            token: (string) $payload['token'],
            chatId: (int) $payload['chat_id'],
            userId: (int) $payload['user_id'],
            issuedAt: (int) ($payload['issued_at'] ?? 0),
        );
    }
}
