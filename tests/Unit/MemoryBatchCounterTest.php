<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Tests\Unit;

use BAGArt\TelegramBotAntispam\Counters\CounterBatch;
use BAGArt\TelegramBotAntispam\Counters\MemoryBatchCounter;
use PHPUnit\Framework\TestCase;

final class MemoryBatchCounterTest extends TestCase
{
    public function test_records_and_returns_window_snapshot(): void
    {
        $counter = new MemoryBatchCounter();

        $snapshot = $counter->record($this->batch(timestamp: 1000));

        self::assertSame(1, $snapshot->messages5s);
        self::assertSame(1, $snapshot->messages30s);
        self::assertSame(1, $snapshot->messages1h);
    }

    public function test_sliding_windows_count_only_recent_events(): void
    {
        $counter = new MemoryBatchCounter();

        $counter->record($this->batch(timestamp: 1000));
        $counter->record($this->batch(timestamp: 1010)); // 10s later — outside 5s window

        $snapshot = $counter->record($this->batch(timestamp: 1020));

        // events at 1000/1010/1020 → within 30s all three, within 5s only the last
        self::assertSame(1, $snapshot->messages5s);
        self::assertSame(3, $snapshot->messages30s);
    }

    public function test_activity_total_combines_dimensions_over_5m(): void
    {
        $counter = new MemoryBatchCounter();

        $snapshot = $counter->record(new CounterBatch(
            botId: 'bot',
            chatId: 1,
            userId: 2,
            eventId: 'm1',
            messages: 1,
            forwards: 1,
            media: 1,
            stickers: 1,
            timestamp: 1000,
        ));

        self::assertSame(4, $snapshot->activityTotal5m);
    }

    public function test_fingerprint_counts_repeats(): void
    {
        $counter = new MemoryBatchCounter();
        $fp = hash('sha256', 'spam text');

        $first = $counter->record($this->batch(timestamp: 1000, fingerprints: [$fp]));
        $second = $counter->record($this->batch(timestamp: 1001, fingerprints: [$fp]));

        self::assertSame(1, $first->fingerprints[$fp]);
        self::assertSame(2, $second->fingerprints[$fp]);
    }

    public function test_fingerprint_cardinality_is_bounded(): void
    {
        $counter = new MemoryBatchCounter(fingerprintCap: 3);

        $fingerprints = array_map(
            static fn (int $i): string => 'fp'.$i,
            range(1, 5),
        );

        $snapshot = $counter->record($this->batch(timestamp: 1000, fingerprints: $fingerprints));

        self::assertCount(3, $snapshot->fingerprints); // cap reached, rest ignored
        self::assertCount(3, $snapshot->recentFingerprints);
    }

    public function test_old_fingerprints_expire_from_window(): void
    {
        $counter = new MemoryBatchCounter(fingerprintWindow: 300);
        $fp = hash('sha256', 'old spam');

        $counter->record($this->batch(timestamp: 1000, fingerprints: [$fp]));
        $later = $counter->record($this->batch(timestamp: 1400, fingerprints: [$fp])); // 400s later

        self::assertSame(1, $later->fingerprints[$fp]); // old occurrence pruned
    }

    private function batch(int $timestamp, array $fingerprints = []): CounterBatch
    {
        return new CounterBatch(
            botId: 'bot',
            chatId: 1,
            userId: 2,
            eventId: uniqid('m', true),
            messages: 1,
            fingerprints: $fingerprints,
            timestamp: $timestamp,
        );
    }
}
