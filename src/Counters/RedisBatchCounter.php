<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Counters;

use BAGArt\TelegramBotAntispam\Domain\CounterSnapshot;
use Closure;
use Illuminate\Support\Facades\Redis;

/**
 * Redis implementation of the Batch Counter API. ONE round trip per message:
 * a single Lua script records all dimensions, prunes expired members,
 * computes window counts and tracks bounded-cardinality fingerprints.
 *
 * Key layout (all keys carry TTL = window + grace):
 *   antispam:c:{bot}:{chat}:{user}:{dim}          ZSET member=eventId score=ts
 *   antispam:fp:{bot}:{chat}:{user}:{bucket}      SET of fingerprint hashes (cardinality cap)
 *   antispam:rep:{bot}:{chat}:{user}:{fp}         ZSET member=eventId score=ts
 */
final class RedisBatchCounter implements Counter
{
    private const string LUA = <<<'LUA'
        local prefix, scope, eventId = ARGV[1], ARGV[2], ARGV[3]
        local now, grace = tonumber(ARGV[4]), tonumber(ARGV[5])
        local fpCap, fpWindow = tonumber(ARGV[6]), tonumber(ARGV[7])
        local dims = cjson.decode(ARGV[8])
        local fingerprints = cjson.decode(ARGV[9])

        for _, d in ipairs(dims) do
            if (d.inc or 0) > 0 then
                local key = prefix .. scope .. ':' .. d.name
                redis.call('ZADD', key, now, eventId .. ':' .. d.name)
                redis.call('EXPIRE', key, d.prune + grace)
            end
        end

        local result = {}
        for _, d in ipairs(dims) do
            if d.counts then
                local key = prefix .. scope .. ':' .. d.name
                redis.call('ZREMRANGEBYSCORE', key, '-inf', now - d.prune)
                for _, w in ipairs(d.counts) do
                    table.insert(result, d.name .. ':' .. w)
                    table.insert(result, tostring(redis.call('ZCOUNT', key, now - w, '+inf')))
                end
            end
        end

        local fpsSetKey = 'antispam:fp:' .. scope .. ':' .. tostring(math.floor(now / fpWindow))
        local recent = {}
        for _, fp in ipairs(fingerprints) do
            if redis.call('SCARD', fpsSetKey) < fpCap then
                redis.call('SADD', fpsSetKey, fp)
                redis.call('EXPIRE', fpsSetKey, fpWindow + grace)
                local repKey = 'antispam:rep:' .. scope .. ':' .. fp
                redis.call('ZADD', repKey, now, eventId)
                redis.call('ZREMRANGEBYSCORE', repKey, '-inf', now - fpWindow)
                redis.call('EXPIRE', repKey, fpWindow + grace)
                table.insert(result, 'fp:' .. fp)
                table.insert(result, tostring(redis.call('ZCARD', repKey)))
                table.insert(recent, fp)
            end
        end
        table.insert(result, 'recent')
        table.insert(result, cjson.encode(recent))

        return result
        LUA;

    /** @var array<string, array<int>> dimension → [prune window, counted windows…] */
    private const array DIMENSIONS = [
        'messages' => [3600, [5, 30, 300, 3600]],
        'forwards' => [300, [30, 300]],
        'media' => [300, [30, 300]],
        'links' => [60, [60]],
        'mentions' => [60, [60]],
        'stickers' => [300, [60, 300]],
    ];

    public function __construct(
        private readonly string $connection = 'default',
        private readonly int $graceSeconds = 10,
        private readonly int $fingerprintCap = 1000,
        private readonly int $fingerprintWindow = 300,
        /** @var callable(string): object|null test seam: resolves the connection by name */
        private readonly ?Closure $connectionResolver = null,
    ) {
    }

    public function record(CounterBatch $batch): CounterSnapshot
    {
        $now = $batch->timestamp ?? time();
        $scope = $batch->botId.':'.$batch->chatId.':'.$batch->userId;

        $dims = [];
        foreach ($batch->increments() as $name => $increment) {
            [$prune, $windows] = self::DIMENSIONS[$name];
            $dims[] = ['name' => $name, 'inc' => $increment, 'prune' => $prune, 'counts' => $windows];
        }

        $connection = $this->connectionResolver !== null
            ? ($this->connectionResolver)($this->connection)
            : Redis::connection($this->connection);

        $result = $connection->eval(
            self::LUA,
            [
                'antispam:c:',
                $scope,
                $batch->eventId,
                $now,
                $this->graceSeconds,
                $this->fingerprintCap,
                $this->fingerprintWindow,
                json_encode($dims),
                json_encode(array_values($batch->fingerprints)),
            ],
            0,
        );

        return $this->toSnapshot(is_array($result) ? $result : [], $batch);
    }

    /** @param  list<string>  $result */
    private function toSnapshot(array $result, CounterBatch $batch): CounterSnapshot
    {
        /** @var array<string, int> $map */
        $map = [];
        $fingerprints = [];
        $recent = [];

        for ($i = 0, $n = count($result); $i + 1 < $n; $i += 2) {
            [$field, $value] = [(string) $result[$i], (string) $result[$i + 1]];
            if ($field === 'recent') {
                $decoded = json_decode($value, true);
                $recent = is_array($decoded) ? $decoded : [];

                continue;
            }
            if (str_starts_with($field, 'fp:')) {
                $fingerprints[substr($field, 3)] = (int) $value;

                continue;
            }
            $map[$field] = (int) $value;
        }

        $messages5m = $map['messages:300'] ?? 0;

        return new CounterSnapshot(
            messages5s: $map['messages:5'] ?? 0,
            messages30s: $map['messages:30'] ?? 0,
            messages5m: $messages5m,
            messages1h: $map['messages:3600'] ?? 0,
            forwards30s: $map['forwards:30'] ?? 0,
            media30s: $map['media:30'] ?? 0,
            links1m: $map['links:60'] ?? 0,
            mentions1m: $map['mentions:60'] ?? 0,
            stickers1m: $map['stickers:60'] ?? 0,
            activityTotal5m: $messages5m
                + ($map['forwards:300'] ?? 0)
                + ($map['media:300'] ?? 0)
                + ($map['stickers:300'] ?? 0),
            fingerprints: $fingerprints,
            recentFingerprints: $recent,
        );
    }
}
