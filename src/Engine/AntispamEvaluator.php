<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Engine;

use BAGArt\TelegramBotAntispam\Domain\AntispamMessageContext;
use BAGArt\TelegramBotAntispam\Domain\EvaluationPlan;
use BAGArt\TelegramBotAntispam\Domain\RiskContext;

/**
 * Pure evaluation orchestrator: engine → aggregator → policy evaluator.
 * No side effects; shared by the webhook processor, dry-run and replay.
 */
final readonly class AntispamEvaluator
{
    public function __construct(
        private RuleEngine $engine,
        private VerdictAggregator $aggregator,
        private PolicyEvaluator $policyEvaluator,
    ) {
    }

    public function evaluate(
        AntispamMessageContext $context,
        EvaluationPlan $plan,
        ?RiskContext $risk,
    ): EvaluationOutcome {
        $detections = $this->engine->evaluate($context, $plan);
        $score = $this->aggregator->aggregate($detections, $plan);
        $verdict = $this->policyEvaluator->evaluate($score, $risk, $plan);

        return new EvaluationOutcome($plan, $context, $risk, $detections, $score, $verdict);
    }
}
