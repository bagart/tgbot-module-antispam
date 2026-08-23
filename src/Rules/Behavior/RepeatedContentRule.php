<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Rules\Behavior;

use BAGArt\TelegramBotAntispam\Counters\MessageFingerprint;

abstract class RepeatedContentRule extends \BAGArt\TelegramBotAntispam\Rules\AntiSpamRule
{
    public function __construct(
        protected readonly MessageFingerprint $fingerprint = new MessageFingerprint(),
    ) {
    }
}
