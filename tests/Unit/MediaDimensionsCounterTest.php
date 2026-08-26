<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Tests\Unit;

use BAGArt\TelegramBotAntispam\Counters\MemoryBatchCounter;
use BAGArt\TelegramBotAntispam\Counters\MessageFingerprint;
use BAGArt\TelegramBotAntispam\Counters\ObservationCollector;
use BAGArt\TelegramBotAntispam\Domain\MessageData;
use PHPUnit\Framework\TestCase;

/**
 * Integration of the P3.5 media dimensions through collector + counter:
 * voices window, cross-chat media relay, animated-emoji fingerprints.
 */
final class MediaDimensionsCounterTest extends TestCase
{
    private const int TS = 1700000000;

    public function test_voice_messages_increment_voices_window(): void
    {
        $counter = new MemoryBatchCounter();
        $collector = new ObservationCollector(new MessageFingerprint());

        $counter->record($collector->collect('bot', 100, 42, self::voice('v1'), self::TS));
        $snapshot = $counter->record($collector->collect('bot', 100, 42, self::voice('v2'), self::TS));

        self::assertSame(2, $snapshot->voices30s);
    }

    public function test_same_media_across_chats_counts_distinct_chats(): void
    {
        $counter = new MemoryBatchCounter();
        $collector = new ObservationCollector(new MessageFingerprint());

        $counter->record($collector->collect('bot', 100, 42, self::photo('same-file', 1), self::TS));
        $counter->record($collector->collect('bot', 200, 43, self::photo('same-file', 2), self::TS));
        // same chat again does not raise the distinct count
        $counter->record($collector->collect('bot', 100, 44, self::photo('same-file', 3), self::TS));
        $snapshot = $counter->record($collector->collect('bot', 300, 45, self::photo('same-file', 4), self::TS));

        $fp = hash('sha256', 'xchat:same-file');
        self::assertSame(3, $snapshot->crossChatMedia[$fp]);
    }

    public function test_different_media_do_not_cross_count(): void
    {
        $counter = new MemoryBatchCounter();
        $collector = new ObservationCollector(new MessageFingerprint());

        $counter->record($collector->collect('bot', 100, 42, self::photo('file-a', 1), self::TS));
        $snapshot = $counter->record($collector->collect('bot', 200, 42, self::photo('file-b', 2), self::TS));

        // The snapshot covers identities of the current message only.
        self::assertSame(1, $snapshot->crossChatMedia[hash('sha256', 'xchat:file-b')]);
        self::assertArrayNotHasKey(hash('sha256', 'xchat:file-a'), $snapshot->crossChatMedia);
    }

    public function test_custom_emoji_entities_are_fingerprinted_per_id(): void
    {
        $counter = new MemoryBatchCounter();
        $collector = new ObservationCollector(new MessageFingerprint());
        $message = self::customEmoji('e1', 10);

        for ($i = 1; $i <= 4; ++$i) {
            $snapshot = $counter->record($collector->collect('bot', 100, 42, $message, self::TS + $i));
        }

        $fp = hash('sha256', 'custom_emoji:e1');
        self::assertSame(4, $snapshot->fingerprints[$fp]);
    }

    private static function voice(string $fileId): MessageData
    {
        return self::message(['hasMedia' => true, 'mediaKind' => 'voice', 'mediaFileId' => $fileId, 'mediaDurationSeconds' => 40]);
    }

    private static function photo(string $fileId, int $messageId): MessageData
    {
        return self::message([
            'messageId' => $messageId,
            'hasMedia' => true,
            'mediaKind' => 'photo',
            'mediaFileId' => $fileId,
        ]);
    }

    private static function customEmoji(string $emojiId, int $messageId): MessageData
    {
        return self::message([
            'messageId' => $messageId,
            'text' => '😀',
            'entities' => [['type' => 'custom_emoji', 'offset' => 0, 'length' => 1, 'custom_emoji_id' => $emojiId]],
        ]);
    }

    private static function message(array $overrides): MessageData
    {
        return new MessageData(...array_replace([
            'messageId' => 10,
            'date' => new \DateTimeImmutable('@'.self::TS),
            'text' => null,
            'entities' => null,
            'hasMedia' => false,
            'mediaKind' => null,
            'mediaFileId' => null,
            'hasSticker' => false,
            'stickerEmoji' => null,
            'caption' => null,
            'isForwarded' => false,
            'isReply' => false,
            'length' => 0,
        ], $overrides));
    }
}
