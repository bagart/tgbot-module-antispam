<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Rules;

use BAGArt\TelegramBotAntispam\Domain\AntiSpamDetection;
use BAGArt\TelegramBotAntispam\Domain\AntispamMessageContext;

/**
 * Pluggable detection source beyond built-in rules (blocklists, reputation,
 * future AI). Sources are evaluated by the same pure engine path.
 */
interface DetectionSource
{
    public function id(): string;

    public function group(): string;

    public function check(AntispamMessageContext $context): ?AntiSpamDetection;
}
