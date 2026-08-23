<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Tests\Unit;

use BAGArt\TelegramBotAntispam\Domain\AggregatedScore;
use BAGArt\TelegramBotAntispam\Domain\AntiSpamDetection;
use BAGArt\TelegramBotAntispam\Domain\DetectionKind;
use BAGArt\TelegramBotAntispam\Domain\DetectionSeverity;
use BAGArt\TelegramBotAntispam\Engine\VerdictAggregator;
use BAGArt\TelegramBotAntispam\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class VerdictAggregatorTest extends TestCase
{
    private VerdictAggregator $aggregator;

    protected function setUp(): void
    {
        $this->aggregator = new VerdictAggregator();
    }

    public function test_group_cap_limits_contribution(): void
    {
        // 3 soft advertising signals: 60+50+40=150 raw → capped at 80
        $score = $this->aggregator->aggregate([
            $this->detection('advertising.regex', 60, 'advertising'),
            $this->detection('advertising.contact', 50, 'advertising'),
            $this->detection('advertising.link_flood', 40, 'advertising'),
        ], Fixtures::plan());

        self::assertSame(['contribution' => 80, 'cap' => 80], $score->groupBreakdown['advertising']);
        self::assertSame(80, $score->total);
    }

    public function test_global_cap_limits_total(): void
    {
        $plan = Fixtures::plan(['globalCap' => 120]);

        $score = $this->aggregator->aggregate([
            $this->detection('a', 80, 'advertising'),
            $this->detection('b', 100, 'flood'),
        ], $plan);

        self::assertSame(120, $score->total);
    }

    public function test_breakdown_lists_only_groups_with_detections(): void
    {
        $score = $this->aggregator->aggregate([
            $this->detection('flood.rate.burst', 30, 'flood'),
        ], Fixtures::plan());

        self::assertSame(['flood'], array_keys($score->groupBreakdown));
        self::assertSame(['contribution' => 30, 'cap' => 100], $score->groupBreakdown['flood']);
    }

    public function test_unknown_group_uses_global_cap(): void
    {
        $score = $this->aggregator->aggregate([
            $this->detection('x', 500, 'unknown_group'),
        ], Fixtures::plan());

        self::assertSame(['contribution' => 200, 'cap' => 200], $score->groupBreakdown['unknown_group']);
    }

    public function test_empty_detections_give_zero_score(): void
    {
        $score = $this->aggregator->aggregate([], Fixtures::plan());
        self::assertSame(0, $score->total);
        self::assertInstanceOf(AggregatedScore::class, $score);
    }

    private function detection(string $ruleId, int $score, string $group): AntiSpamDetection
    {
        return new AntiSpamDetection(
            ruleId: $ruleId,
            score: $score,
            severity: DetectionSeverity::Low,
            kind: DetectionKind::Soft,
            group: $group,
            reason: 'test',
        );
    }
}
