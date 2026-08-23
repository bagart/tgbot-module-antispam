<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Rules\Behavior;

use BAGArt\TelegramBotAntispam\Domain\AntiSpamDetection;
use BAGArt\TelegramBotAntispam\Domain\AntispamMessageContext;
use BAGArt\TelegramBotAntispam\Domain\DetectionSeverity;
use BAGArt\TelegramBotAntispam\Domain\EvaluationPlan;
use BAGArt\TelegramBotAntispam\Domain\RuleRequirements;
use BAGArt\TelegramBotAntispam\Rules\DetectionDefaults;

/** Repeated identical text (normalized fingerprint) within the fingerprint window. */
final class RepeatedTextRule extends RepeatedContentRule
{
    private const string ID = 'flood.repeat_text';
    private const int DEFAULT_REPEAT_LIMIT = 3;

    public function id(): string
    {
        return self::ID;
    }

    public function group(): string
    {
        return 'flood';
    }

    public function requirements(): RuleRequirements
    {
        return new RuleRequirements(requiresText: true, counters: ['fingerprints']);
    }

    public function check(AntispamMessageContext $context, EvaluationPlan $plan): ?AntiSpamDetection
    {
        $text = $context->message->effectiveText();
        if ($text === null || $text === '') {
            return null;
        }

        $fingerprint = $this->fingerprint->of($text);
        if ($fingerprint === null) {
            return null;
        }

        return $this->detectOnCount($context, $plan, $fingerprint);
    }

    protected function detectOnCount(AntispamMessageContext $context, EvaluationPlan $plan, string $fingerprint): ?AntiSpamDetection
    {
        $count = $context->behavior->fingerprints[$fingerprint] ?? 0;
        $limit = $plan->paramOf(static::ID, static::DEFAULT_REPEAT_LIMIT);

        if ($count >= $limit) {
            return $this->detection(
                $plan,
                40,
                new DetectionDefaults(40, DetectionSeverity::Medium),
                "Repeated content: {$count}x within window >= {$limit}",
                ['occurrences' => $count],
            );
        }

        return null;
    }
}
