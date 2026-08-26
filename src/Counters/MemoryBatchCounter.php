<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Counters;

use BAGArt\TelegramBotAntispam\Domain\CounterSnapshot;

/**
 * In-memory implementation with the same semantics as RedisBatchCounter:
 * sliding windows, bounded key lifetime (prune on read), bounded fingerprint
 * cardinality. Used in unit/feature tests and pure-PHP contexts.
 */
final class MemoryBatchCounter implements Counter
{
    /** @var array<string, array<int, int>> scope:dim → [timestamp, …] */
    private array $events = [];

    /** @var array<string, array<string, array<int, int>>> scope → fingerprint → [timestamp, …] */
    private array $fingerprints = [];

    /** @var array<string, array<int, string>> scope:bucket → hashes */
    private array $fingerprintSets = [];

    /** @var array<string, array<int, int>> bot:fingerprint → chatId → last timestamp */
    private array $crossChat = [];

    public function __construct(
        private readonly int $graceSeconds = 10,
        private readonly int $fingerprintCap = 1000,
        private readonly int $fingerprintWindow = 300,
    ) {
    }

    public function record(CounterBatch $batch): CounterSnapshot
    {
        $now = $batch->timestamp ?? time();
        $scope = $batch->botId.':'.$batch->chatId.':'.$batch->userId;

        foreach ($batch->increments() as $name => $increment) {
            if ($increment > 0) {
                [$prune] = self::dimensions()[$name];
                $key = $scope.':'.$name;
                $this->events[$key][] = $now;
                $this->prune($key, $now - $prune);
            }
        }

        $counts = [];
        foreach (self::dimensions() as $name => [$prune, $windows]) {
            $key = $scope.':'.$name;
            $this->prune($key, $now - $prune);
            foreach ($windows as $window) {
                $counts["$name:$window"] = $this->countIn($key, $now - $window);
            }
        }

        $bucket = (int) floor($now / $this->fingerprintWindow);
        $fpsKey = $scope.':'.$bucket;
        $recorded = [];
        foreach ($batch->fingerprints as $fp) {
            if (count($this->fingerprintSets[$fpsKey] ?? []) >= $this->fingerprintCap) {
                continue;
            }
            $this->fingerprintSets[$fpsKey][$fp] = true;

            $repKey = $scope.':rep:'.$fp;
            $this->fingerprints[$repKey][$fp][] = $now;
            $list = &$this->fingerprints[$repKey][$fp];
            $list = array_values(array_filter($list, fn (int $ts): bool => $ts >= $now - $this->fingerprintWindow));
            unset($list);
            $recorded[$fp] = count($this->fingerprints[$repKey][$fp]);
        }

        $messages5m = $counts['messages:300'] ?? 0;

        return new CounterSnapshot(
            messages5s: $counts['messages:5'] ?? 0,
            messages30s: $counts['messages:30'] ?? 0,
            messages5m: $messages5m,
            messages1h: $counts['messages:3600'] ?? 0,
            forwards30s: $counts['forwards:30'] ?? 0,
            media30s: $counts['media:30'] ?? 0,
            voices30s: $counts['voices:30'] ?? 0,
            links1m: $counts['links:60'] ?? 0,
            mentions1m: $counts['mentions:60'] ?? 0,
            stickers1m: $counts['stickers:60'] ?? 0,
            activityTotal5m: $messages5m + ($counts['forwards:300'] ?? 0) + ($counts['media:300'] ?? 0) + ($counts['stickers:300'] ?? 0),
            fingerprints: $recorded,
            recentFingerprints: array_keys($recorded),
            crossChatMedia: $this->recordCrossChat($batch, $now),
        );
    }

    /** @return array<string, int> fingerprint → distinct chats seen within the window */
    private function recordCrossChat(CounterBatch $batch, int $now): array
    {
        $out = [];
        foreach ($batch->crossChatFingerprints as $fp) {
            $key = $batch->botId.':'.$fp;
            $chats = &$this->crossChat[$key];
            $chats ??= [];
            $chats[$batch->chatId] = $now;
            // prune stale chat entries (bounded by distinct-chat cardinality cap)
            if (count($chats) > $this->fingerprintCap) {
                $chats = array_slice($chats, -$this->fingerprintCap, null, true);
            }
            unset($chats);
            $active = array_filter($this->crossChat[$key], fn (int $ts): bool => $ts >= $now - $this->fingerprintWindow);
            $out[$fp] = count($active);
        }

        return $out;
    }

    /** @return array<string, array{int, list<int>}> */
    private static function dimensions(): array
    {
        return [
            'messages' => [3600, [5, 30, 300, 3600]],
            'forwards' => [300, [30, 300]],
            'media' => [300, [30, 300]],
            'voices' => [300, [30]],
            'links' => [60, [60]],
            'mentions' => [60, [60]],
            'stickers' => [300, [60, 300]],
        ];
    }

    private function prune(string $key, int $minTimestamp): void
    {
        if (! isset($this->events[$key])) {
            return;
        }
        $this->events[$key] = array_values(
            array_filter($this->events[$key], fn (int $ts): bool => $ts >= $minTimestamp),
        );
    }

    private function countIn(string $key, int $minTimestamp): int
    {
        $count = 0;
        foreach ($this->events[$key] ?? [] as $ts) {
            if ($ts >= $minTimestamp) {
                ++$count;
            }
        }

        return $count;
    }
}
