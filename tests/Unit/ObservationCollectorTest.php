<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Tests\Unit;

use BAGArt\TelegramBotAntispam\Counters\MessageFingerprint;
use BAGArt\TelegramBotAntispam\Counters\ObservationCollector;
use BAGArt\TelegramBotAntispam\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class ObservationCollectorTest extends TestCase
{
    private ObservationCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new ObservationCollector;
    }

    public function test_media_identity_uses_file_unique_id(): void
    {
        $context = Fixtures::context(text: null, messageOverrides: [
            'hasMedia' => true,
            'mediaKind' => 'photo',
            'mediaFileId' => 'AgAC-file-1',
        ]);
        [, , $message] = [$context->user, $context->chat, $context->message];

        $batch = $this->collector->collect('bot', 100, 42, $message);

        self::assertSame(
            [hash('sha256', 'file:AgAC-file-1')],
            $batch->fingerprints,
        );
    }

    public function test_sticker_without_file_id_falls_back_to_emoji_basis(): void
    {
        $context = Fixtures::context(text: null, messageOverrides: [
            'hasSticker' => true,
            'stickerEmoji' => '\u{1F389}',
            'mediaFileId' => null,
        ]);

        $batch = $this->collector->collect('bot', 100, 42, $context->message);

        self::assertSame([hash('sha256', 'sticker:\u{1F389}')], $batch->fingerprints);
    }

    public function test_links_and_mentions_counted_from_entities(): void
    {
        $context = Fixtures::context(text: 'see https://x.com and @user', messageOverrides: [
            'entities' => [
                ['type' => 'url', 'offset' => 4, 'length' => 13],
                ['type' => 'mention', 'offset' => 22, 'length' => 5],
                ['type' => 'bold', 'offset' => 0, 'length' => 3],
            ],
        ]);

        $batch = $this->collector->collect('bot', 100, 42, $context->message);

        self::assertSame(1, $batch->links);
        self::assertSame(1, $batch->mentions);
        self::assertSame(1, $batch->messages);
    }

    public function test_forward_increments_forward_dimension(): void
    {
        $context = Fixtures::context(messageOverrides: ['isForwarded' => true]);

        $batch = $this->collector->collect('bot', 100, 42, $context->message);

        self::assertSame(1, $batch->forwards);
    }

    public function test_collector_and_rules_share_fingerprint_semantics(): void
    {
        $fp = new MessageFingerprint;
        $context = Fixtures::context(text: 'Buy SPAM now!!!');

        $batch = $this->collector->collect('bot', 100, 42, $context->message);

        // RepeatedTextRule looks up the collector's hash — must be identical
        self::assertSame([$fp->of('buy spam now')], $batch->fingerprints);
    }
}
