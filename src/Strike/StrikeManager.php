<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Strike;

use BAGArt\TelegramBotAntispam\Domain\StrikeConsequence;
use BAGArt\TelegramBotAntispam\Models\AntispamStrikeEvent;
use BAGArt\TelegramBotAntispam\Models\AntispamUserStrikes;
use BAGArt\TelegramBotAntispam\Models\AntispamViolation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Strike lifecycle with DB serialization: PostgreSQL SELECT … FOR UPDATE is
 * the correctness source of truth. The lock covers read active strikes →
 * calculate consequence → insert event → update aggregate.
 *
 * Invariants: 1 violation = max 1 strike event (UNIQUE(violation_id) DB guard);
 * `antispam_user_strikes.active_strikes` is a cache aggregate only.
 */
final readonly class StrikeManager
{
    private const string RISK_CACHE_PREFIX = 'antispam:risk:';

    public function __construct(
        private EscalationPolicy $escalationPolicy,
        private CacheInterface $cache,
    ) {
    }

    /**
     * Registers the strike for a violation. Retry-safe: an existing event for
     * the violation is returned unchanged.
     */
    public function registerStrike(AntispamViolation $violation, ?\DateTimeImmutable $now = null): AntispamStrikeEvent
    {
        $existing = AntispamStrikeEvent::query()
            ->where('violation_id', $violation->id)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $now ??= new \DateTimeImmutable();

        try {
            $event = DB::transaction(function () use ($violation, $now): AntispamStrikeEvent {
                $activeStrikes = AntispamStrikeEvent::query()
                    ->where('bot_id', $violation->bot_id)
                    ->where('chat_id', $violation->chat_id)
                    ->where('user_id', $violation->user_id)
                    ->where('expired_at', '>', $now)
                    ->lockForUpdate()
                    ->get(['created_at']);

                $consequence = $this->escalationPolicy->calculate($activeStrikes, $now);

                $event = AntispamStrikeEvent::create([
                    'violation_id' => $violation->id,
                    'bot_id' => $violation->bot_id,
                    'chat_id' => $violation->chat_id,
                    'user_id' => $violation->user_id,
                    'strike_consequence' => $consequence->value,
                    'expired_at' => $consequence->expiresAt($now),
                    'active' => true,
                ]);

                $this->upsertAggregate($violation, $now, $consequence, $event);

                return $event;
            });
        } catch (UniqueConstraintViolationException) {
            // Concurrent worker won the UNIQUE(violation_id) race
            return AntispamStrikeEvent::query()->where('violation_id', $violation->id)->firstOrFail();
        }

        $this->invalidateRiskCache($violation);

        return $event;
    }

    /** Aligns the aggregate cache with the timestamp source of truth. */
    public function refreshActiveCache(string $botId, int $chatId, int $userId, ?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable();

        $activeCount = AntispamStrikeEvent::query()
            ->where('bot_id', $botId)
            ->where('chat_id', $chatId)
            ->where('user_id', $userId)
            ->where('expired_at', '>', $now)
            ->count();

        AntispamUserStrikes::query()
            ->where('bot_id', $botId)
            ->where('chat_id', $chatId)
            ->where('user_id', $userId)
            ->update(['active_strikes' => $activeCount]);

        return $activeCount;
    }

    private function upsertAggregate(
        AntispamViolation $violation,
        \DateTimeImmutable $now,
        StrikeConsequence $consequence,
        AntispamStrikeEvent $event,
    ): void {
        $aggregate = AntispamUserStrikes::query()
            ->where('bot_id', $violation->bot_id)
            ->where('chat_id', $violation->chat_id)
            ->where('user_id', $violation->user_id)
            ->lockForUpdate()
            ->first();

        $isBan = $consequence === StrikeConsequence::Ban;

        if ($aggregate === null) {
            AntispamUserStrikes::create([
                'bot_id' => $violation->bot_id,
                'chat_id' => $violation->chat_id,
                'user_id' => $violation->user_id,
                'active_strikes' => 1,
                'total_strikes' => 1,
                'last_offense_at' => $now,
                'last_violation_id' => $violation->id,
                'muted_until' => $event->expired_at,
                'banned_at' => $isBan ? $now : null,
            ]);

            return;
        }

        $aggregate->timestamps = false;
        $aggregate->total_strikes += 1;
        $aggregate->last_offense_at = $now;
        $aggregate->last_violation_id = $violation->id;
        if ($event->expired_at !== null) {
            $aggregate->muted_until = $aggregate->muted_until !== null && $aggregate->muted_until > $event->expired_at
                ? $aggregate->muted_until
                : $event->expired_at;
        }
        if ($isBan) {
            $aggregate->banned_at = $now;
        }
        $aggregate->save();
    }

    private function invalidateRiskCache(AntispamViolation $violation): void
    {
        try {
            $this->cache->delete(
                self::RISK_CACHE_PREFIX.$violation->bot_id.':'.$violation->chat_id.':'.$violation->user_id,
            );
        } catch (Throwable) {
            // TTL bounds staleness anyway
        }
    }
}
