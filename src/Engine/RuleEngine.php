<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Engine;

use BAGArt\TelegramBotAntispam\Domain\AntiSpamDetection;
use BAGArt\TelegramBotAntispam\Domain\AntispamMessageContext;
use BAGArt\TelegramBotAntispam\Domain\EvaluationPlan;
use BAGArt\TelegramBotAntispam\Domain\RuleRequirements;
use BAGArt\TelegramBotAntispam\Rules\AntiSpamRule;

/**
 * Pure evaluation: (context, plan) → Detection[]. No DB/Redis/Telegram calls.
 * Determinism invariant: same input + same plan = same detections.
 */
final readonly class RuleEngine
{
    /** @param  list<AntiSpamRule>  $rules */
    public function __construct(
        private array $rules,
    ) {
    }

    /** @return list<AntiSpamDetection> */
    public function evaluate(AntispamMessageContext $context, EvaluationPlan $plan): array
    {
        $detections = [];
        foreach ($this->rules as $rule) {
            if (! $this->enabledFor($rule, $plan)) {
                continue;
            }
            if (! $this->applicable($rule->requirements(), $context)) {
                continue;
            }

            $detection = $rule->check($context, $plan);
            if ($detection !== null) {
                $detections[] = $detection;
            }
        }

        return $detections;
    }

    private function enabledFor(AntispamRule $rule, EvaluationPlan $plan): bool
    {
        return $plan->isEnabled($rule->id());
    }

    /**
     * Rule Applicability Index: pre-filter by message facts so plain text
     * never evaluates media/forward/sticker rules.
     */
    private function applicable(RuleRequirements $requirements, AntispamMessageContext $context): bool
    {
        $message = $context->message;

        return ! ($requirements->requiresText && $message->effectiveText() === null)
            && ! ($requirements->requiresEntities && $message->entities === null)
            && ! ($requirements->requiresMedia && ! $message->hasMedia)
            && ! ($requirements->requiresSticker && ! $message->hasSticker)
            && ! ($requirements->requiresForward && ! $message->isForwarded);
    }
}
