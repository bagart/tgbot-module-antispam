<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Tests\Unit;

use BAGArt\TelegramBotAntispam\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class RuleEngineTest extends TestCase
{
    public function test_plain_text_skips_media_forward_sticker_rules(): void
    {
        $engine = Fixtures::engine();
        $context = Fixtures::context(text: 'just a normal message');

        foreach ($engine->evaluate($context, Fixtures::plan()) as $detection) {
            self::assertNotContains($detection->ruleId, [
                'flood.forward',
                'flood.repeat_media',
                'flood.media',
                'flood.repeat_sticker',
                'flood.sticker_emoji',
            ]);
        }

        self::assertSame([], Fixtures::engine()->evaluate($context, Fixtures::plan()));
    }

    public function test_advertising_pattern_is_hard_detection(): void
    {
        $detections = Fixtures::engine()
            ->evaluate(Fixtures::context('join t.me/spamchannel now'), Fixtures::plan());

        $ad = array_values(array_filter($detections, fn ($d): bool => str_starts_with($d->ruleId, 'advertising')));
        self::assertNotSame([], $ad);
        self::assertSame('hard', $ad[0]->kind->value);
    }

    public function test_soft_signals_only_contribute_score(): void
    {
        // "Hi, write me at test@gmail.com" — soft contact signal must NOT ban
        // (the exact case the RFC eliminated: soft set ≠ ban without hard)
        $outcome = Fixtures::evaluator()->evaluate(
            Fixtures::context('Hi, write me at test@gmail.com'),
            Fixtures::plan(),
            null,
        );

        // Single soft signal (+30) must stay below every enforcement level:
        // soft sets are group-capped and never ban without a hard detection
        self::assertTrue($outcome->allows());
        self::assertSame(30, $outcome->score->total);
    }

    public function test_flood_burst_fires_on_rate(): void
    {
        $behavior = new \BAGArt\TelegramBotAntispam\Domain\BehaviorContext(messages5s: 6);
        $detections = Fixtures::engine()->evaluate(Fixtures::context(behavior: $behavior), Fixtures::plan());

        self::assertContains('flood.rate.burst', array_map(fn ($d) => $d->ruleId, $detections));
    }

    public function test_disabled_rule_does_not_fire(): void
    {
        $plan = Fixtures::plan(['enabledRules' => ['flood.rate' => false]]);
        $behavior = new \BAGArt\TelegramBotAntispam\Domain\BehaviorContext(messages5s: 100);

        $detections = Fixtures::engine()->evaluate(Fixtures::context(behavior: $behavior), $plan);

        self::assertNotContains('flood.rate.burst', array_map(fn ($d) => $d->ruleId, $detections));
    }

    public function test_character_flood_requires_ratio_and_length(): void
    {
        // AAAA... ratio ~1.0, long → fires
        $detections = Fixtures::engine()->evaluate(
            Fixtures::context(str_repeat('A', 40)),
            Fixtures::plan(),
        );
        self::assertContains('flood.character', array_map(fn ($d) => $d->ruleId, $detections));

        // mixed text with enough length but low dominant-char ratio → no fire
        $mixed = 'AAAAAAAAAA!!! Привет, как дела сегодня, друзья?';
        $detections = Fixtures::engine()->evaluate(Fixtures::context($mixed), Fixtures::plan());
        self::assertNotContains('flood.character', array_map(fn ($d) => $d->ruleId, $detections));
    }

    public function test_oversized_message_fires(): void
    {
        $detections = Fixtures::engine()->evaluate(
            Fixtures::context(str_repeat('word ', 1200)),
            Fixtures::plan(),
        );

        self::assertContains('flood.size', array_map(fn ($d) => $d->ruleId, $detections));
    }

    public function test_engine_is_pure_same_input_same_detections(): void
    {
        $context = Fixtures::context('join t.me/spam now');
        $plan = Fixtures::plan();

        $first = Fixtures::engine()->evaluate($context, $plan);
        $second = Fixtures::engine()->evaluate($context, $plan);

        self::assertSame(
            array_map(static fn ($d) => [$d->ruleId, $d->score], $first),
            array_map(static fn ($d) => [$d->ruleId, $d->score], $second),
        );
    }

    public function test_missing_forward_metadata_never_signals(): void
    {
        // forwarded=false + forward counters present → ForwardFlood MUST not fire
        $behavior = new \BAGArt\TelegramBotAntispam\Domain\BehaviorContext(forwards30s: 100);

        $detections = Fixtures::engine()->evaluate(
            Fixtures::context('normal text', behavior: $behavior),
            Fixtures::plan(),
        );

        self::assertNotContains('flood.forward', array_map(fn ($d) => $d->ruleId, $detections));
    }
}
