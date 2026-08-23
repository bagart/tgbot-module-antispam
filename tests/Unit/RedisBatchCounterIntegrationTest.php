<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Tests\Unit;

use BAGArt\TelegramBotAntispam\Counters\CounterBatch;
use BAGArt\TelegramBotAntispam\Counters\RedisBatchCounter;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\TestCase;

/**
 * Live-Redis integration: sliding-window boundary, bounded TTL, fingerprint cap.
 * Skipped automatically when Redis is unreachable (CI without redis service).
 */
final class RedisBatchCounterIntegrationTest extends TestCase
{
    private static bool $available = false;

    public static function setUpBeforeClass(): void
    {
        try {
            $pong = Redis::connection((string) getenv('REDIS_CONNECTION') ?: 'default')->command('PING');
            self::$available = $pong === true || $pong === 'PONG' || $pong === '+PONG';
        } catch (\Throwable) {
            self::$available = false;
        }
    }

    protected function setUp(): void
    {
        if (! self::$available) {
            $this->markTestSkipped('Redis is not reachable — integration counters skipped.');
        }
    }

    public function test_sliding_window_boundary_counts_recent_events_only(): void
    {
        $counter = new RedisBatchCounter(connection: 'default');

        $counter->record($this->batch('m1', timestamp: time() - 7));
        $counter->record($this->batch('m2', timestamp: time() - 2));
        $snapshot = $counter->record($this->batch('m3', timestamp: time()));

        // 5s window keeps only the last two events; 30s window keeps all three
        self::assertSame(2, $snapshot->messages5s);
        self::assertSame(3, $snapshot->messages30s);
    }

    public function test_keys_expire_within_window_plus_grace(): void
    {
        $counter = new RedisBatchCounter(connection: 'default', graceSeconds: 10);
        $scope = 'ttlbot:1:77';

        $counter->record(new CounterBatch(botId: 'ttlbot', chatId: 1, userId: 77, eventId: 'k1', messages: 1, timestamp: time()));

        $ttl = Redis::connection('default')->ttl("antispam:c:$scope:messages");
        self::assertNotFalse($ttl);
        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(3600 + 10, $ttl); // window + grace bound
    }

    public function test_fingerprint_cardinality_cap(): void
    {
        $counter = new RedisBatchCounter(connection: 'default', fingerprintCap: 3);

        $fingerprints = array_map(static fn (int $i): string => hash('sha256', 'fp'.$i.time()), range(1, 6));
        $snapshot = $counter->record($this->batch('cap1', fingerprints: array_slice($fingerprints, 0, 4)));
        $snapshot = $counter->record($this->batch('cap2', fingerprints: array_slice($fingerprints, 4, 2)));

        self::assertCount(3, $snapshot->recentFingerprints);
    }

    private function batch(string $eventId, ?int $timestamp = null, array $fingerprints = []): CounterBatch
    {
        return new CounterBatch(
            botId: 'bot',
            chatId: 1,
            userId: 2,
            eventId: $eventId.uniqid('', true),
            messages: 1,
            fingerprints: $fingerprints,
            timestamp: $timestamp ?? time(),
        );
    }
}
