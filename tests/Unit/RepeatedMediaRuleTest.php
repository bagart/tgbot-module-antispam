<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Tests\Unit;

use BAGArt\TelegramBotAntispam\Domain\BehaviorContext;
use BAGArt\TelegramBotAntispam\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class RepeatedMediaRuleTest extends TestCase
{
    public function test_same_file_unique_id_repeated_fires_detection(): void
    {
        $fpHash = hash('sha256', 'file:AgAC-same-1');
        $behavior = new BehaviorContext(media30s: 1, fingerprints: [$fpHash => 3]);

        $detections = Fixtures::engine()->evaluate(
            Fixtures::context(text: null, behavior: $behavior, messageOverrides: [
                'hasMedia' => true,
                'mediaKind' => 'photo',
                'mediaFileId' => 'AgAC-same-1',
            ]),
            Fixtures::plan(),
        );

        self::assertContains('flood.repeat_media', array_map(fn ($d) => $d->ruleId, $detections));
    }

    public function test_different_media_within_rate_stays_clean(): void
    {
        // unique file id not yet repeated (count 1) + rate below limit
        $behavior = new BehaviorContext(media30s: 2, fingerprints: []);

        $detections = Fixtures::engine()->evaluate(
            Fixtures::context(text: null, behavior: $behavior, messageOverrides: [
                'hasMedia' => true,
                'mediaKind' => 'photo',
                'mediaFileId' => 'AgAC-other-9',
            ]),
            Fixtures::plan(),
        );

        self::assertNotContains('flood.repeat_media', array_map(fn ($d) => $d->ruleId, $detections));
    }

    public function test_burst_of_different_media_fires_rate_branch(): void
    {
        $behavior = new BehaviorContext(media30s: 5, fingerprints: []);

        $detections = Fixtures::engine()->evaluate(
            Fixtures::context(text: null, behavior: $behavior, messageOverrides: [
                'hasMedia' => true,
                'mediaKind' => 'photo',
                'mediaFileId' => 'AgAC-unique-x',
            ]),
            Fixtures::plan(),
        );

        $repeat = array_values(array_filter($detections, fn ($d) => $d->ruleId === 'flood.repeat_media'));
        self::assertNotSame([], $repeat);
        self::assertSame('rate', $repeat[0]->metadata['by']);
    }
}
