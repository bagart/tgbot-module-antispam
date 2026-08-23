<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Rules;

use BAGArt\TelegramBotAntispam\Domain\DetectionKind;
use BAGArt\TelegramBotAntispam\Domain\DetectionSeverity;

/** Rule-declared defaults for score/severity/kind; the plan may override each. */
final readonly class DetectionDefaults
{
    public function __construct(
        public int $score,
        public DetectionSeverity $severity = DetectionSeverity::Low,
        public DetectionKind $kind = DetectionKind::Soft,
    ) {
    }
}
