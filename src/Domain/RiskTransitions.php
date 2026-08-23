<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Domain;

/**
 * Explicit score→action adjustments per risk level. Risk can only RAISE an
 * action through these configured transitions; it never lowers below the hard
 * minimum (enforced by PolicyEvaluator, not by this map).
 */
final readonly class RiskTransitions
{
    /**
     * @param  array<string, array{at_score: int, action: string}>  $transitions  risk level → transition
     */
    public function __construct(
        public array $transitions = [
            RiskContext::LEVEL_LOW => ['at_score' => 70, 'action' => 'warn'],
            RiskContext::LEVEL_HIGH => ['at_score' => 70, 'action' => 'restrict'],
        ],
    ) {
    }

    /** @return array{at_score: int, action: string}|null */
    public function forLevel(string $level): ?array
    {
        return $this->transitions[$level] ?? null;
    }
}
