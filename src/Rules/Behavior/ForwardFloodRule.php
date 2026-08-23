<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Rules\Behavior;

use BAGArt\TelegramBotAntispam\Domain\AntiSpamDetection;
use BAGArt\TelegramBotAntispam\Domain\AntispamMessageContext;
use BAGArt\TelegramBotAntispam\Domain\EvaluationPlan;
use BAGArt\TelegramBotAntispam\Domain\RuleRequirements;
use BAGArt\TelegramBotAntispam\Rules\AntiSpamRule;
use BAGArt\TelegramBotAntispam\Rules\DetectionDefaults;

/**
 * Forward flood. Missing forward metadata on the current message → no
 * detection (absence of metadata is not a spam signal).
 */
final class ForwardFloodRule extends AntiSpamRule
{
    private const string ID = 'flood.forward';
    private const int DEFAULT_FORWARD_LIMIT = 4;

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
        return new RuleRequirements(requiresForward: true, counters: ['forwards']);
    }

    public function check(AntispamMessageContext $context, EvaluationPlan $plan): ?AntiSpamDetection
    {
        if (! $context->message->isForwarded) {
            return null;
        }

        $limit = $plan->paramOf(self::ID, self::DEFAULT_FORWARD_LIMIT);
        $count = $context->behavior->forwards30s;

        if ($count >= $limit) {
            return $this->detection(
                $plan,
                40,
                new DetectionDefaults(40, DetectionSeverity::Medium),
                "Forward flood: {$count} in 30s >= {$limit}",
                ['occurrences' => $count],
            );
        }

        return null;
    }
}
