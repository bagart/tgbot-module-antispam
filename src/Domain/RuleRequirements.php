<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Domain;

/**
 * What a rule needs from the message/counters. The engine pre-filters rules
 * against available facts: plain text → ForwardFlood/RepeatedMedia are skipped.
 */
final readonly class RuleRequirements
{
    /**
     * @param  list<string>  $counters  counter dimensions required (messages, forwards, media, voices, links, mentions, stickers, fingerprints, cross_chat)
     */
    public function __construct(
        public bool $requiresText = false,
        public bool $requiresEntities = false,
        public bool $requiresMedia = false,
        public bool $requiresSticker = false,
        public bool $requiresForward = false,
        public array $counters = [],
    ) {
    }

    public function requiresCounters(): bool
    {
        return $this->counters !== [];
    }
}
