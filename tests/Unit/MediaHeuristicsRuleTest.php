<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Tests\Unit;

use BAGArt\TelegramBotAntispam\Domain\BehaviorContext;
use BAGArt\TelegramBotAntispam\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

final class MediaHeuristicsRuleTest extends TestCase
{
    public function test_long_voice_flood_fires_detection(): void
    {
        $behavior = new BehaviorContext(voices30s: 3);

        $detections = Fixtures::engine()->evaluate(
            Fixtures::context(text: null, behavior: $behavior, messageOverrides: [
                'hasMedia' => true,
                'mediaKind' => 'voice',
                'mediaFileId' => 'voice-1',
                'mediaDurationSeconds' => 45,
            ]),
            Fixtures::plan(),
        );

        self::assertContains('flood.voice30s', array_map(fn ($d) => $d->ruleId, $detections));
    }

    public function test_short_voice_stays_clean(): void
    {
        $behavior = new BehaviorContext(voices30s: 5);

        $detections = Fixtures::engine()->evaluate(
            Fixtures::context(text: null, behavior: $behavior, messageOverrides: [
                'hasMedia' => true,
                'mediaKind' => 'voice',
                'mediaFileId' => 'voice-2',
                'mediaDurationSeconds' => 10,
            ]),
            Fixtures::plan(),
        );

        self::assertNotContains('flood.voice30s', array_map(fn ($d) => $d->ruleId, $detections));
    }

    public function test_single_long_voice_below_rate_limit_stays_clean(): void
    {
        $behavior = new BehaviorContext(voices30s: 1);

        $detections = Fixtures::engine()->evaluate(
            Fixtures::context(text: null, behavior: $behavior, messageOverrides: [
                'hasMedia' => true,
                'mediaKind' => 'voice',
                'mediaFileId' => 'voice-3',
                'mediaDurationSeconds' => 60,
            ]),
            Fixtures::plan(),
        );

        self::assertNotContains('flood.voice30s', array_map(fn ($d) => $d->ruleId, $detections));
    }

    public function test_repeated_animated_emoji_fires_detection(): void
    {
        $emojiId = 'emoji-777';
        $fpHash = hash('sha256', 'custom_emoji:'.$emojiId);
        $behavior = new BehaviorContext(fingerprints: [$fpHash => 5]);

        $detections = Fixtures::engine()->evaluate(
            Fixtures::context(text: '😀😀😀', behavior: $behavior, messageOverrides: [
                'entities' => [
                    ['type' => 'custom_emoji', 'offset' => 0, 'length' => 1, 'custom_emoji_id' => $emojiId],
                ],
            ]),
            Fixtures::plan(),
        );

        $rule = array_values(array_filter($detections, fn ($d) => $d->ruleId === 'flood.animated_emoji'));
        self::assertNotSame([], $rule);
        self::assertSame($emojiId, $rule[0]->metadata['customEmojiId']);
    }

    public function test_first_animated_emoji_stays_clean(): void
    {
        $behavior = new BehaviorContext();

        $detections = Fixtures::engine()->evaluate(
            Fixtures::context(text: '😀', behavior: $behavior, messageOverrides: [
                'entities' => [
                    ['type' => 'custom_emoji', 'offset' => 0, 'length' => 1, 'custom_emoji_id' => 'fresh-1'],
                ],
            ]),
            Fixtures::plan(),
        );

        self::assertNotContains('flood.animated_emoji', array_map(fn ($d) => $d->ruleId, $detections));
    }

    public function test_same_media_across_chats_fires_detection(): void
    {
        $fpHash = hash('sha256', 'xchat:relay-file-1');
        $behavior = new BehaviorContext(crossChatMedia: [$fpHash => 3]);

        $detections = Fixtures::engine()->evaluate(
            Fixtures::context(text: null, behavior: $behavior, messageOverrides: [
                'hasMedia' => true,
                'mediaKind' => 'video',
                'mediaFileId' => 'relay-file-1',
            ]),
            Fixtures::plan(),
        );

        $rule = array_values(array_filter($detections, fn ($d) => $d->ruleId === 'flood.media_cross_chat'));
        self::assertNotSame([], $rule);
        self::assertSame(3, $rule[0]->metadata['distinctChats']);
    }

    public function test_media_seen_in_one_chat_only_stays_clean(): void
    {
        $fpHash = hash('sha256', 'xchat:local-file-1');
        $behavior = new BehaviorContext(crossChatMedia: [$fpHash => 1]);

        $detections = Fixtures::engine()->evaluate(
            Fixtures::context(text: null, behavior: $behavior, messageOverrides: [
                'hasMedia' => true,
                'mediaKind' => 'photo',
                'mediaFileId' => 'local-file-1',
            ]),
            Fixtures::plan(),
        );

        self::assertNotContains('flood.media_cross_chat', array_map(fn ($d) => $d->ruleId, $detections));
    }

    public function test_spammy_caption_on_media_fires_caption_ad_rule(): void
    {
        $detections = Fixtures::engine()->evaluate(
            Fixtures::context(text: null, messageOverrides: [
                'hasMedia' => true,
                'mediaKind' => 'photo',
                'mediaFileId' => 'photo-1',
                'caption' => 'join t.me/spam_channel now',
            ]),
            Fixtures::plan(),
        );

        $ids = array_map(fn ($d) => $d->ruleId, $detections);

        self::assertContains('advertising.media_caption', $ids);
        // caption-only ads must not double-fire the text-body rule
        self::assertNotContains('advertising.regex', $ids);
    }

    public function test_plain_text_ad_does_not_fire_the_caption_rule(): void
    {
        $detections = Fixtures::engine()->evaluate(
            Fixtures::context(text: 'join t.me/spam_channel now'),
            Fixtures::plan(),
        );

        $ids = array_map(fn ($d) => $d->ruleId, $detections);

        self::assertContains('advertising.regex', $ids);
        self::assertNotContains('advertising.media_caption', $ids);
    }

    public function test_benign_media_caption_stays_clean(): void
    {
        $detections = Fixtures::engine()->evaluate(
            Fixtures::context(text: null, messageOverrides: [
                'hasMedia' => true,
                'mediaKind' => 'video',
                'mediaFileId' => 'video-1',
                'caption' => 'sunset at the lake last summer',
            ]),
            Fixtures::plan(),
        );

        $ids = array_map(fn ($d) => $d->ruleId, $detections);

        self::assertNotContains('advertising.media_caption', $ids);
    }
}
