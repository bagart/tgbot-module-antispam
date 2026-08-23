<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Domain;

final readonly class ChatContext
{
    public function __construct(
        public int $chatId,
        public string $type,
        public ?bool $isAdmin = null,
        public ?\DateTimeImmutable $memberSince = null,
    ) {
    }
}
