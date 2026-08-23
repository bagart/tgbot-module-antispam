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
            links: $links,
            mentions: $mentions,
            stickers: $message->hasSticker ? 1 : 0,
            fingerprints: $this->fingerprintsOf($message),
            timestamp: $timestamp,
        );
    }

    /** @return list<string> */
    private function fingerprintsOf(MessageData $message): array
    {
        // file_unique_id is the strongest identity: same photo/sticker resent = same fingerprint
        if ($message->mediaFileId !== null) {
            return [hash('sha256', 'file:'.$message->mediaFileId)];
        }

        if ($message->hasSticker) {
            return [hash('sha256', 'sticker:'.($message->stickerEmoji ?? 'unknown'))];
        }

        $fp = $this->fingerprint->of($message->effectiveText());

        return $fp === null ? [] : [$fp];
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
