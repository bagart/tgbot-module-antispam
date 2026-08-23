<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Domain;

final readonly class RuleGroup
{
    public function __construct(
        public string $id,
        public int $cap,
        public string $title = '',
    ) {
    }
}
