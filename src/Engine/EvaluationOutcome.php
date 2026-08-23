<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Engine;

use BAGArt\TelegramBotAntispam\Domain\AggregatedScore;
use BAGArt\TelegramBotAntispam\Domain\AntiSpamVerdict;
use BAGArt\TelegramBotAntispam\Domain\AntispamMessageContext;
use BAGArt\TelegramBotAntispam\Domain\EvaluationPlan;
use BAGArt\TelegramBotAntispam\Domain\RiskContext;

/** Result of one pure evaluation pass — everything downstream needs. */
final readonly class EvaluationOutcome
{
    public function __construct(
        public EvaluationPlan $plan,
        public AntispamMessageContext $context,
        public ?RiskContext $risk,
        public array $detections,
        public AggregatedScore $score,
        public AntiSpamVerdict $verdict,
    ) {
    }

    public function allows(): bool
    {
        return $this->verdict->allows();
    }
}
