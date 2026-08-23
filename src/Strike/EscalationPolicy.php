<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Strike;

use BAGArt\TelegramBotAntispam\Domain\StrikeConsequence;

/**
 * Escalation ladder with hysteresis: burst offenses escalate fast, isolated
 * offenses after a quiet period restart the ladder. Pure and deterministic.
 *
 * Ladder position = (number of currently ACTIVE strikes) + 1 for this offense.
 * Strike 1 → mute_1h · 2 → mute_6h · 3 → mute_24h · 4+ → ban.
 */
final class EscalationPolicy
{
    private const string DEFAULT_LADDER = 'none,mute_1h,mute_6h,mute_24h,ban';

    /** @var list<string> */
    private array $ladder;

    public function __construct(
        private readonly int $decayWindowDays = 7,
        string $ladder = self::DEFAULT_LADDER,
    ) {
        $this->ladder = array_map(
            static fn (string $step): string => trim($step),
            explode(',', $ladder),
        );
    }

    /**
     * @param  iterable<array{created_at?: string|\DateTimeInterface|null}>  $activeStrikes  existing ACTIVE events (expired_at > now), excluding the current offense
     */
    public function calculate(iterable $activeStrikes, \DateTimeImmutable $now): StrikeConsequence
    {
        $count = 0;
        $lastOffenseAt = null;
        foreach ($activeStrikes as $strike) {
            ++$count;
            $createdAt = $strike['created_at'] ?? null;
            if ($createdAt instanceof \DateTimeInterface) {
                $createdAt = \DateTimeImmutable::createFromInterface($createdAt);
            }
            if (is_string($createdAt)) {
                $createdAt = new \DateTimeImmutable($createdAt);
            }
            if ($createdAt !== null && ($lastOffenseAt === null || $createdAt > $lastOffenseAt)) {
                $lastOffenseAt = $createdAt;
            }
        }

        // Hysteresis: a long quiet period resets the ladder instead of stacking
        if ($lastOffenseAt !== null && $now->getTimestamp() - $lastOffenseAt->getTimestamp() > $this->decayWindowDays * 86400) {
            $count = 0;
        }

        // +1: this offense occupies the next ladder step after all active ones
        $index = min(count($this->ladder) - 1, $count + 1);

        return StrikeConsequence::fromName($this->ladder[$index]);
    }
}
