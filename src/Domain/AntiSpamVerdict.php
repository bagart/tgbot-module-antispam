<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Domain;

/** Final deterministic result of policy evaluation. */
final readonly class AntiSpamVerdict
{
    public function __construct(
        public EnforcementAction $action,
        public int $score,
        public string $reason,
        public string $policyVersion,
        /** @var array{warn: int, restrict: int, ban: int} */
        public array $thresholds,
        /** @var list<string> contributing rule ids */
        public array $matchedRules = [],
    ) {
    }

    public function allows(): bool
    {
        return $this->action === EnforcementAction::Warn && $this->score < $this->thresholds['warn'];
    }
}
