<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Rules\Behavior;

use BAGArt\TelegramBotAntispam\Domain\AntiSpamDetection;
use BAGArt\TelegramBotAntispam\Domain\AntispamMessageContext;
use BAGArt\TelegramBotAntispam\Domain\EvaluationPlan;
use BAGArt\TelegramBotAntispam\Domain\DetectionSeverity;
use BAGArt\TelegramBotAntispam\Domain\RuleRequirements;
use BAGArt\TelegramBotAntispam\Rules\AntiSpamRule;
use BAGArt\TelegramBotAntispam\Rules\DetectionDefaults;

/** Oversized messages: walls of text beyond the configured length cap. */
final class OversizedMessageRule extends AntiSpamRule
{
    private const string ID = 'flood.size';
    private const int DEFAULT_MAX_LENGTH = 4096;

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
        return new RuleRequirements(requiresText: true);
    }

    public function check(AntispamMessageContext $context, EvaluationPlan $plan): ?AntiSpamDetection
    {
        $maxLength = $plan->paramOf(self::ID, self::DEFAULT_MAX_LENGTH);
        $text = $context->message->effectiveText();

        if ($context->message->length >= $maxLength || mb_strlen((string) $text) >= $maxLength) {
            return $this->detection(
                $plan,
                20,
                new DetectionDefaults(20, DetectionSeverity::Info),
                "Oversized message: {$context->message->length} chars >= {$maxLength}",
            );
        }

        return null;
    }
}
