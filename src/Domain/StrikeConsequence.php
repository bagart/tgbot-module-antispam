<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Domain;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

/** Consequence applied by the strike escalation ladder (not the message-level action). */
enum StrikeConsequence: string
{
    case None = 'none';
    case Mute1h = 'mute_1h';
    case Mute6h = 'mute_6h';
    case Mute24h = 'mute_24h';
    case Ban = 'ban';

    public function duration(): ?DateInterval
    {
        return match ($this) {
            self::None => null,
            self::Mute1h => new DateInterval('PT1H'),
            self::Mute6h => new DateInterval('PT6H'),
            self::Mute24h => new DateInterval('P1D'),
            self::Ban => null,
        };
    }

    public function expiresAt(DateTimeImmutable $from): ?DateTimeImmutable
    {
        return match ($this) {
            self::None, self::Ban => null,
            default => $from->add($this->duration()),
        };
    }

    public function isBan(): bool
    {
        return $this === self::Ban;
    }

    public static function fromName(string $value): self
    {
        $consequence = self::tryFrom(strtolower($value));
        if ($consequence === null) {
            throw new InvalidArgumentException("Unknown strike consequence '{$value}'");
        }

        return $consequence;
    }
}
