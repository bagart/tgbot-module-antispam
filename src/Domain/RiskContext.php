<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Domain;

/**
 * Trust context — a policy modifier, never a spam detector. Risk modifies the
 * action only through explicit policy transitions and can never lower the
 * verdict below a hard-detection minimum.
 */
final readonly class RiskContext
{
    public const LEVEL_LOW = 'low';
    public const LEVEL_MEDIUM = 'medium';
    public const LEVEL_HIGH = 'high';

    public function __construct(
        public string $level,
        public ?int $accountAgeDays,
        public ?int $chatMemberAgeDays,
        public int $previousMessages,
        public int $previousViolations,
        public string $riskVersion,
    ) {
    }

    /** @return list<string> */
    public static function levels(): array
    {
        return [self::LEVEL_LOW, self::LEVEL_MEDIUM, self::LEVEL_HIGH];
    }
}
