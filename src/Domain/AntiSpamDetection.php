<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Domain;

/**
 * One rule outcome. score is the contribution BEFORE group caps; the
 * aggregator applies per-group and global caps afterwards.
 */
final readonly class AntiSpamDetection
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $ruleId,
        public int $score,
        public DetectionSeverity $severity,
        public DetectionKind $kind,
        public string $group,
        public string $reason,
        public array $metadata = [],
    ) {
    }
}
