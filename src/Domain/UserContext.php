<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Domain;

/** Normalized sender facts extracted from the Telegram message DTO. */
final readonly class UserContext
{
    public function __construct(
        public int $userId,
        public ?string $username,
        public bool $isBot,
        public ?bool $isPremium = null,
    ) {
    }
}
