<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Domain;

/**
 * Behavioral section of the evaluation context, built from CounterSnapshot
 * before evaluation. Pure data — no Redis handles.
 */
final readonly class BehaviorContext
{
    /**
     * @param  array<string, int>  $fingerprints  normalized fingerprint → occurrences within window
     * @param  list<string>  $recentFingerprints  fingerprints of recent messages (bounded)
     */
    public function __construct(
        public int $messages5s = 0,
        public int $messages30s = 0,
        public int $messages5m = 0,
        public int $messages1h = 0,
        public int $forwards30s = 0,
        public int $media30s = 0,
        public int $links1m = 0,
        public int $mentions1m = 0,
        public int $stickers1m = 0,
        public int $activityTotal5m = 0,
        public array $fingerprints = [],
        public array $recentFingerprints = [],
    ) {
    }
}
