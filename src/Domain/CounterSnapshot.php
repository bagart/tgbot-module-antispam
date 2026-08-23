<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Domain;

/**
 * Result of one batch counter record: everything behavioral rules need,
 * computed inside the counter (≤2 Redis round trips per message).
 */
final readonly class CounterSnapshot
{
    /**
     * @param  array<string, int>  $fingerprints  fingerprint → occurrences in window
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

    public function behaviorContext(): BehaviorContext
    {
        return new BehaviorContext(
            messages5s: $this->messages5s,
            messages30s: $this->messages30s,
            messages5m: $this->messages5m,
            messages1h: $this->messages1h,
            forwards30s: $this->forwards30s,
            media30s: $this->media30s,
            links1m: $this->links1m,
            mentions1m: $this->mentions1m,
            stickers1m: $this->stickers1m,
            activityTotal5m: $this->activityTotal5m,
            fingerprints: $this->fingerprints,
            recentFingerprints: $this->recentFingerprints,
        );
    }
}
