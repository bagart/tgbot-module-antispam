<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Domain;

/**
 * Score after per-group capping and the global cap.
 *
 * @param  array<string, array{contribution: int, cap: int}>  $groupBreakdown
 */
final readonly class AggregatedScore
{
    /**
     * @param  array<string, array{contribution: int, cap: int}>  $groupBreakdown
     * @param  list<AntiSpamDetection>  $detections
     */
    public function __construct(
        public int $total,
        public int $globalCap,
        public array $groupBreakdown,
        public array $detections,
    ) {
    }
}
