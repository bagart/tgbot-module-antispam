<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Domain;

/**
 * Normalized message facts. Missing Telegram metadata (forward origin, entities,
 * media) is represented by null/false — rules MUST treat absence as "no detection",
 * never as a spam signal.
 */
final readonly class MessageData
{
    public function __construct(
        public int $messageId,
        public \DateTimeImmutable $date,
        public ?string $text,
        public ?array $entities,
        public bool $hasMedia,
        public ?string $mediaKind, // photo/video/document/audio/animation/voice/video_note
        public ?string $mediaFileId, // file_unique_id — true media identity across sizes
        public bool $hasSticker,
        public ?string $stickerEmoji,
        public ?string $caption,
        public bool $isForwarded,
        public bool $isReply,
        public int $length,
        public ?int $mediaDurationSeconds = null, // voice/video_note/audio/video duration
    ) {
    }

    /** Text body including caption — captions carry spam just like text bodies. */
    public function effectiveText(): ?string
    {
        if ($this->text !== null && $this->text !== '') {
            return $this->text;
        }

        return $this->caption !== null && $this->caption !== '' ? $this->caption : null;
    }
}
