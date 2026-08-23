<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Domain;

use InvalidArgumentException;

/**
 * Message-level enforcement action. Ordered: comparisons drive the
 * "hard detection sets a minimum level" policy rule.
 */
enum EnforcementAction: string
{
    case Warn = 'warn';
    case Delete = 'delete';
    case Restrict = 'restrict';
    case Ban = 'ban';

    /** @var array<string, int> */
    private const ORDER = [
        self::Warn->value => 0,
        self::Delete->value => 1,
        self::Restrict->value => 2,
        self::Ban->value => 3,
    ];

    public function rank(): int
    {
        return self::ORDER[$this->value];
    }

    public function isAtLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    /** Stronger of the two actions — used to combine hard minimums with score policy. */
    public function strongest(self $other): self
    {
        return $this->rank() >= $other->rank() ? $this : $other;
    }

    public static function fromName(string $value): self
    {
        $action = self::tryFrom(strtolower($value));
        if ($action === null) {
            throw new InvalidArgumentException("Unknown enforcement action '{$value}'");
        }

        return $action;
    }
}
