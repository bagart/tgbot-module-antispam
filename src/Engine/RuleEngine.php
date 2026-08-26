<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Engine;

use BAGArt\TelegramBotAntispam\Domain\AntiSpamDetection;
use BAGArt\TelegramBotAntispam\Domain\AntispamMessageContext;
use BAGArt\TelegramBotAntispam\Domain\EvaluationPlan;
use BAGArt\TelegramBotAntispam\Domain\RuleRequirements;
use BAGArt\TelegramBotAntispam\Rules\AntiSpamRule;
use BAGArt\TelegramBotAntispam\Rules\DetectionSource;

/**
 * Pure evaluation: (context, plan) → Detection[]. No DB/Redis/Telegram calls.
 * Determinism invariant: same input + same plan = same detections.
 *
 * DetectionSources (honeypot, future reputation/AI) run through the same
 * path as built-in rules and share the aggregator's group caps.
 */
final readonly class RuleEngine
{
    /**
     * @param  list<AntiSpamRule>  $rules
     * @param  list<DetectionSource>  $sources
     */
    public function __construct(
        private array $rules,
        private array $sources = [],
    ) {
    }

    /** @return list<AntiSpamDetection> */
    public function evaluate(AntispamMessageContext $context, EvaluationPlan $plan): array
    {
        $detections = [];
        foreach ($this->rules as $rule) {
            if (! $this->enabledFor($rule->id(), $plan)) {
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

        foreach ($this->sources as $source) {
            if (! $this->enabledFor($source->id(), $plan)) {
                continue;
            }
            if (! $this->applicable($source->requirements(), $context)) {
                continue;
            }

            $detection = $source->check($context);
            if ($detection !== null) {
                $detections[] = $detection;
            }
        }

        return $detections;
    }

    private function enabledFor(string $id, EvaluationPlan $plan): bool
    {
        return $plan->isEnabled($id);
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
