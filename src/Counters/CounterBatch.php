<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Counters;

/**
 * Events of one message to record. Fingerprints must be pre-normalized
 * (MessageFingerprint::of) and hashed.
 */
final readonly class CounterBatch
{
    /**
     * @param  list<string>  $fingerprints  fingerprint hashes of this message
     * @param  list<string>  $crossChatFingerprints  media identity hashes tracked bot-wide across chats
     */
    public function __construct(
        public string $botId,
        public int $chatId,
        public int $userId,
        public string $eventId,
        public int $messages = 0,
        public int $forwards = 0,
        public int $media = 0,
        public int $voices = 0,
        public int $links = 0,
        public int $mentions = 0,
        public int $stickers = 0,
        public array $fingerprints = [],
        public ?int $timestamp = null,
        public array $crossChatFingerprints = [],
    ) {
    }

    /** @return array<string, int> */
    public function increments(): array
    {
        return [
            'messages' => $this->messages,
            'forwards' => $this->forwards,
            'media' => $this->media,
            'voices' => $this->voices,
            'links' => $this->links,
            'mentions' => $this->mentions,
            'stickers' => $this->stickers,
        ];
    }
}
