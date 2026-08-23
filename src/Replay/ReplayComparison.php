<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Replay;

use BAGArt\TelegramBotAntispam\Domain\AntiSpamVerdict;
use BAGArt\TelegramBotAntispam\Domain\EnforcementAction;

final readonly class ReplayComparison
{
    public function __construct(
        public string $violationId,
        public EnforcementAction $oldAction,
        public EnforcementAction $newAction,
        public int $oldScore,
        public AntiSpamVerdict $newVerdict,
    ) {
    }

    public function changed(): bool
    {
        return $this->oldAction !== $this->newAction;
    }
}
