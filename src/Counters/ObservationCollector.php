<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Counters;

use BAGArt\TelegramBotAntispam\Domain\MessageData;

/**
 * Extracts counter events from normalized message facts: dimension increments
 * and pre-normalized fingerprints for Repeated* rules.
 */
final readonly class ObservationCollector
{
    public function __construct(
        private MessageFingerprint $fingerprint = new MessageFingerprint(),
    ) {
    }

    public function collect(string $botId, int $chatId, int $userId, MessageData $message, ?int $timestamp = null): CounterBatch
    {
        [$links, $mentions] = $this->countEntities($message->entities);
        $hasMedia = $message->hasMedia || $message->mediaKind !== null;

        return new CounterBatch(
            botId: $botId,
            chatId: $chatId,
            userId: $userId,
            eventId: (string) $message->messageId,
            messages: 1,
            forwards: $message->isForwarded ? 1 : 0,
            media: $hasMedia ? 1 : 0,
            voices: $message->mediaKind === 'voice' ? 1 : 0,
            links: $links,
            mentions: $mentions,
            stickers: $message->hasSticker ? 1 : 0,
            fingerprints: $this->fingerprintsOf($message),
            timestamp: $timestamp,
            crossChatFingerprints: $this->crossChatFingerprintsOf($message),
        );
    }

    /** @return list<string> */
    private function fingerprintsOf(MessageData $message): array
    {
        // file_unique_id is the strongest identity: same photo/sticker resent = same fingerprint
        if ($message->mediaFileId !== null) {
            $out = [hash('sha256', 'file:'.$message->mediaFileId)];
        } elseif ($message->hasSticker) {
            $out = [hash('sha256', 'sticker:'.($message->stickerEmoji ?? 'unknown'))];
        } else {
            $fp = $this->fingerprint->of($message->effectiveText());
            $out = $fp === null ? [] : [$fp];
        }

        // Animated emoji arrive as custom_emoji entities — tracked per emoji id
        foreach ($this->customEmojiIds($message) as $id) {
            $out[] = hash('sha256', 'custom_emoji:'.$id);
        }

        return $out;
    }

    /** @return list<string> distinct custom_emoji ids of the message */
    private function customEmojiIds(MessageData $message): array
    {
        $ids = [];
        foreach ($message->entities ?? [] as $entity) {
            if (($entity['type'] ?? '') === 'custom_emoji' && isset($entity['custom_emoji_id'])) {
                $ids[$entity['custom_emoji_id']] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Media identities tracked bot-wide (cross-chat relay detection). Distinct
     * from per-user fingerprints: the same file posted by DIFFERENT accounts
     * across chats is the spam signal here.
     *
     * @return list<string>
     */
    private function crossChatFingerprintsOf(MessageData $message): array
    {
        if (! $message->hasMedia || $message->mediaFileId === null) {
            return [];
        }

        return [hash('sha256', 'xchat:'.$message->mediaFileId)];
    }

    /**
     * @param  list<array{type: string, offset: int, length: int, url?: string}>|null  $entities
     * @return array{int, int}
     */
    private function countEntities(?array $entities): array
    {
        $links = 0;
        $mentions = 0;
        foreach ($entities ?? [] as $entity) {
            match ($entity['type']) {
                'url', 'text_link' => ++$links,
                'mention', 'text_mention' => ++$mentions,
                default => null,
            };
        }

        return [$links, $mentions];
    }
}
