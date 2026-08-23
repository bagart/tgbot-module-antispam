<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Domain;

/**
 * hard — can raise the enforcement floor via severity→action mapping
 * (never an automatic ban); soft — contributes to score only.
 */
enum DetectionKind: string
{
    case Soft = 'soft';
    case Hard = 'hard';
}
