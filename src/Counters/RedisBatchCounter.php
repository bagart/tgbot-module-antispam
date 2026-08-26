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
    /**
     * Returns ONE JSON payload (immune to phpredis Lua-table indexing quirks):
     * {"counts": {"messages:5": "2", "fp:<hash>": "3"}, "recent": ["<hash>", …]}
     */
    private const string LUA = <<<'LUA'
        local prefix, scope, eventId = ARGV[1], ARGV[2], ARGV[3]
        local now, grace = tonumber(ARGV[4]), tonumber(ARGV[5])
        local fpCap, fpWindow = tonumber(ARGV[6]), tonumber(ARGV[7])
        local dims = cjson.decode(ARGV[8])
        local fingerprints = cjson.decode(ARGV[9])
        local botId = ARGV[10]
        local crossChat = cjson.decode(ARGV[11])
        local xmCap = tonumber(ARGV[12])

        for _, d in ipairs(dims) do
            if (d.inc or 0) > 0 then
                local key = prefix .. scope .. ':' .. d.name
                redis.call('ZADD', key, now, eventId .. ':' .. d.name)
                redis.call('EXPIRE', key, d.prune + grace)
            end
        end

        local counts = {}
        for _, d in ipairs(dims) do
            if d.counts then
                local key = prefix .. scope .. ':' .. d.name
                redis.call('ZREMRANGEBYSCORE', key, '-inf', now - d.prune)
                for _, w in ipairs(d.counts) do
                    counts[d.name .. ':' .. w] = tostring(redis.call('ZCOUNT', key, now - w, '+inf'))
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
                counts['fp:' .. fp] = tostring(redis.call('ZCARD', repKey))
                table.insert(recent, fp)
            end
        end

        -- Cross-chat media relay: bot-wide hash chatId -> hit count per media identity
        local chatId = ARGV[13]
        for _, fp in ipairs(crossChat) do
            local key = 'antispam:xm:' .. botId .. ':' .. fp
            if redis.call('HLEN', key) < xmCap or redis.call('HEXISTS', key, chatId) == 1 then
                redis.call('HINCRBY', key, chatId, 1)
                redis.call('EXPIRE', key, fpWindow + grace)
                counts['xmchats:' .. fp] = tostring(redis.call('HLEN', key))
            end
        end

        return cjson.encode({ counts = counts, recent = recent })
        LUA;

    /** @var array<string, array<int>> dimension → [prune window, counted windows…] */
    private const array DIMENSIONS = [
        'messages' => [3600, [5, 30, 300, 3600]],
        'forwards' => [300, [30, 300]],
        'media' => [300, [30, 300]],
        'voices' => [300, [30]],
        'links' => [60, [60]],
        'mentions' => [60, [60]],
        'stickers' => [300, [60, 300]],
    ];

    public function __construct(
        private readonly string $connection = 'default',
        private readonly int $graceSeconds = 10,
        private readonly int $fingerprintCap = 1000,
        private readonly int $fingerprintWindow = 300,
        private readonly int $crossChatCap = 1000,
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

        $payload = $connection->eval(
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
                $batch->botId,
                json_encode(array_values($batch->crossChatFingerprints)),
                $this->crossChatCap,
                (string) $batch->chatId,
            ],
            0,
        );

        $data = is_string($payload) ? json_decode($payload, true) : null;
        $counts = is_array($data['counts'] ?? null) ? array_map('strval', $data['counts']) : [];
        $recent = is_array($data['recent'] ?? null) ? array_map('strval', $data['recent']) : [];

        return $this->toSnapshot($counts, $recent);
    }

    /** @param  array<string, string>  $counts  field → count ("messages:5", "fp:<hash>") */
    private function toSnapshot(array $counts, array $recent): CounterSnapshot
    {
        /** @var array<string, int> $map */
        $map = [];
        $fingerprints = [];

        foreach ($counts as $field => $value) {
            if (str_starts_with((string) $field, 'fp:')) {
                $fingerprints[substr((string) $field, 3)] = (int) $value;

                continue;
            }
            $map[(string) $field] = (int) $value;
        }

        $messages5m = $map['messages:300'] ?? 0;
        $crossChat = [];
        foreach ($map as $field => $value) {
            if (str_starts_with((string) $field, 'xmchats:')) {
                $crossChat[substr((string) $field, 8)] = $value;
            }
        }

        return new CounterSnapshot(
            messages5s: $map['messages:5'] ?? 0,
            messages30s: $map['messages:30'] ?? 0,
            messages5m: $messages5m,
            messages1h: $map['messages:3600'] ?? 0,
            forwards30s: $map['forwards:30'] ?? 0,
            media30s: $map['media:30'] ?? 0,
            voices30s: $map['voices:30'] ?? 0,
            links1m: $map['links:60'] ?? 0,
            mentions1m: $map['mentions:60'] ?? 0,
            stickers1m: $map['stickers:60'] ?? 0,
            activityTotal5m: $messages5m
                + ($map['forwards:300'] ?? 0)
                + ($map['media:300'] ?? 0)
                + ($map['stickers:300'] ?? 0),
            fingerprints: $fingerprints,
            recentFingerprints: $recent,
            crossChatMedia: $crossChat,
        );
    }
}
