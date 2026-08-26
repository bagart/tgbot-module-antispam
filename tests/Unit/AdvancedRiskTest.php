<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Tests\Unit;

use BAGArt\TelegramBotAntispam\Domain\BehaviorContext;
use BAGArt\TelegramBotAntispam\Domain\RiskContext;
use BAGArt\TelegramBotAntispam\Risk\RiskContextBuilder;
use BAGArt\TelegramBotAntispam\Risk\HoneypotDetector;
use BAGArt\TelegramBotAntispam\Risk\RiskSignals;
use BAGArt\TelegramBotAntispam\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * P3.8 advanced risk: honeypot instant HIGH, reputation and registration
 * attribute bumps, versioned mapping.
 */
final class AdvancedRiskTest extends TestCase
{
    public function test_honeypot_hit_forces_high_level(): void
    {
        $builder = new RiskContextBuilder(new \BAGArt\AsyncKernel\Wrappers\ASKCacheWrapper(new \Illuminate\Cache\ArrayStore()));

        $risk = $builder->build('bot', 100, 42, new BehaviorContext(), signals: new RiskSignals(honeypotHit: true));

        self::assertSame(RiskContext::LEVEL_HIGH, $risk->level);
        self::assertSame(RiskContextBuilder::VERSION, $risk->riskVersion);
    }

    public function test_cross_bot_reputation_raises_level(): void
    {
        $builder = new RiskContextBuilder(new \BAGArt\AsyncKernel\Wrappers\ASKCacheWrapper(new \Illuminate\Cache\ArrayStore()));

        $clean = $builder->build('bot', 100, 42, new BehaviorContext(), signals: new RiskSignals());
        $banned = $builder->build('bot', 100, 42, new BehaviorContext(), signals: new RiskSignals(reputationBans: 2));

        self::assertSame(RiskContext::LEVEL_LOW, $clean->level);
        self::assertSame(RiskContext::LEVEL_HIGH, $banned->level);
    }

    public function test_accountless_forwarder_profile_bumps_level(): void
    {
        $builder = new RiskContextBuilder(new \BAGArt\AsyncKernel\Wrappers\ASKCacheWrapper(new \Illuminate\Cache\ArrayStore()));

        $noUsername = $builder->build('bot', 100, 42, new BehaviorContext(), signals: new RiskSignals(hasUsername: false));
        $forwarder = $builder->build('bot', 100, 42, new BehaviorContext(), signals: new RiskSignals(hasUsername: false, isForwarded: true));
        $premium = $builder->build('bot', 100, 42, new BehaviorContext(), signals: new RiskSignals(isPremium: false));

        self::assertSame(RiskContext::LEVEL_MEDIUM, $noUsername->level);
        self::assertSame(RiskContext::LEVEL_HIGH, $forwarder->level);
        // premium flag alone never changes the level
        self::assertSame(RiskContext::LEVEL_LOW, $premium->level);
    }

    public function test_reputation_degrades_to_zero_without_storage(): void
    {
        // No Laravel app in unit scope: storage failure must degrade to zero.
        $builder = new RiskContextBuilder(new \BAGArt\AsyncKernel\Wrappers\ASKCacheWrapper(new \Illuminate\Cache\ArrayStore()));

        self::assertSame(0, $builder->reputationBans(42));
    }

    public function test_honeypot_detector_matches_configured_words(): void
    {
        $settings = ['honeypot' => ['words' => ['free-crypto', 'spam.link']]];

        $hit = Fixtures::context(text: 'join our free-crypto channel', settings: $settings);
        $miss = Fixtures::context(text: 'nice weather today', settings: $settings);
        $unconfigured = Fixtures::context(text: 'join our free-crypto channel');

        $engine = Fixtures::engine();

        $detections = $engine->evaluate($hit, Fixtures::plan());
        self::assertContains(HoneypotDetector::SOURCE_ID, array_map(fn ($d) => $d->ruleId, $detections));

        self::assertNotContains(
            HoneypotDetector::SOURCE_ID,
            array_map(fn ($d) => $d->ruleId, $engine->evaluate($miss, Fixtures::plan())),
        );
        self::assertNotContains(
            HoneypotDetector::SOURCE_ID,
            array_map(fn ($d) => $d->ruleId, $engine->evaluate($unconfigured, Fixtures::plan())),
        );
    }
}
