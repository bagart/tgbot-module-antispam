<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Rules;

use BAGArt\TelegramBotAntispam\Domain\AntiSpamDetection;
use BAGArt\TelegramBotAntispam\Domain\AntispamMessageContext;
use BAGArt\TelegramBotAntispam\Domain\EvaluationPlan;
use BAGArt\TelegramBotAntispam\Domain\RuleRequirements;

/**
 * A detection source evaluated by the pure engine. Rules MUST NOT touch
 * Redis/DB/Telegram; missing metadata means "no detection", never a signal.
 */
abstract class AntiSpamRule
{
    abstract public function id(): string;

    abstract public function group(): string;

    abstract public function check(AntispamMessageContext $context, EvaluationPlan $plan): ?AntiSpamDetection;

    public function requirements(): RuleRequirements
    {
        return new RuleRequirements();
    }

    /**
     * Detection factory: applies plan overrides (score/severity/kind) over rule defaults.
     * $ruleIdOverride supports one-class-many-ids rules (MessageRateRule windows).
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function detection(
        EvaluationPlan $plan,
        int $score,
        DetectionDefaults $defaults,
        string $reason,
        array $metadata = [],
        ?string $ruleIdOverride = null,
    ): AntiSpamDetection {
        $id = $ruleIdOverride ?? $this->id();

        return new AntiSpamDetection(
            ruleId: $id,
            score: $plan->scoreOf($id, $score),
            severity: $plan->severityOf($id, $defaults->severity),
            kind: $plan->kindOf($id, $defaults->kind),
            group: $this->group(),
            reason: $reason,
            metadata: $metadata,
        );
    }
}
