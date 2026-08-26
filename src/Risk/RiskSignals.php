<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Risk;

/**
 * Extra deterministic risk inputs (todo.antispam.md P3.8): honeypot hit,
 * cross-bot reputation (federated ban count), registration attributes
 * available from the Bot API. Pure data — built once per message.
 */
final readonly class RiskSignals
{
    public function __construct(
        public bool $honeypotHit = false,
        public int $reputationBans = 0,
        public bool $hasUsername = true,
        public bool $isForwarded = false,
        public ?bool $isPremium = null,
    ) {
    }
}
