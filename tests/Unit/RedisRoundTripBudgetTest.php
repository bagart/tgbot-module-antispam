<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Tests\Unit;

use BAGArt\TelegramBotAntispam\Counters\CounterBatch;
use BAGArt\TelegramBotAntispam\Counters\MessageFingerprint;
use BAGArt\TelegramBotAntispam\Counters\RedisBatchCounter;
use PHPUnit\Framework\TestCase;

/**
 * Proves the round-trip budget contract: ONE record() = exactly ONE eval call.
 * Uses a connection-resolver seam — no Laravel facade, no live Redis required.
 */
final class RedisRoundTripBudgetTest extends TestCase
{
    public function test_record_issues_exactly_one_redis_call(): void
    {
        $spy = new class () {
            public int $evalCalls = 0;

            public function eval(string $script, array $args = [], int $numKeys = 0): string
            {
                ++$this->evalCalls;

                return '{"counts":{"messages:5":"1","messages:30":"1"},"recent":[]}';
            }
        };

        $counter = new RedisBatchCounter(
            connectionResolver: fn (string $name): object => $spy,
        );

        $snapshot = $counter->record(new CounterBatch(
            botId: 'bot',
            chatId: 1,
            userId: 2,
            eventId: 'm1',
            messages: 1,
        ));

        self::assertSame(1, $spy->evalCalls);
        self::assertSame(1, $snapshot->messages5s);
    }

    public function test_fingerprint_hash_is_stable_across_normalizations(): void
    {
        // the rule side must compute the same key the collector recorded
        $fp = new MessageFingerprint();
        self::assertSame($fp->of('Hello  World!'), $fp->of('hello world'));
    }
}
