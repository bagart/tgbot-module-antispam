<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Tests\Unit;

use BAGArt\TelegramBotAntispam\Domain\StrikeConsequence;
use BAGArt\TelegramBotAntispam\Strike\EscalationPolicy;
use PHPUnit\Framework\TestCase;

final class EscalationPolicyTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-08-22 12:00:00');
    }

    public function test_first_strike_is_mute_1h(): void
    {
        self::assertSame(
            StrikeConsequence::Mute1h,
            $this->policy()->calculate([], $this->now),
        );
    }

    public function test_ladder_escalates_with_active_strikes(): void
    {
        $one = [['created_at' => '2026-08-22 11:00:00']];
        $two = [$one[0], ['created_at' => '2026-08-22 11:30:00']];
        $three = [...$two, ['created_at' => '2026-08-22 11:45:00']];

        self::assertSame(StrikeConsequence::Mute6h, $this->policy()->calculate($one, $this->now));
        self::assertSame(StrikeConsequence::Mute24h, $this->policy()->calculate($two, $this->now));
        self::assertSame(StrikeConsequence::Ban, $this->policy()->calculate($three, $this->now));
    }

    public function test_four_plus_strikes_stay_ban(): void
    {
        $four = [
            ['created_at' => '2026-08-22 09:00:00'],
            ['created_at' => '2026-08-22 10:00:00'],
            ['created_at' => '2026-08-22 11:00:00'],
            ['created_at' => '2026-08-22 11:50:00'],
        ];

        self::assertSame(StrikeConsequence::Ban, $this->policy()->calculate($four, $this->now));
    }

    public function test_hysteresis_quiet_period_resets_ladder(): void
    {
        // last offense 8 days ago (> decay window 7d): ladder restarts at mute_1h
        $old = [['created_at' => '2026-08-14 10:00:00']];

        self::assertSame(StrikeConsequence::Mute1h, $this->policy()->calculate($old, $this->now));
    }

    public function test_recent_burst_does_not_reset(): void
    {
        $recent = [
            ['created_at' => '2026-08-22 06:00:00'],
            ['created_at' => '2026-08-22 11:59:00'],
        ];

        // 2 active strikes + this offense = 3rd ladder step (mute_24h)
        self::assertSame(StrikeConsequence::Mute24h, $this->policy()->calculate($recent, $this->now));
    }

    private function policy(): EscalationPolicy
    {
        return new EscalationPolicy(decayWindowDays: 7);
    }
}
