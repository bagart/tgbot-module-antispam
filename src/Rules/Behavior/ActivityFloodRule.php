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

/**
 * Combined activity rate: messages + media + stickers + forwards over 5m.
 * Catches mixed-type spam that each single-window rule misses.
 */
final class ActivityFloodRule extends AntiSpamRule
{
    private const string ID = 'flood.activity';
    private const int DEFAULT_ACTIVITY_LIMIT = 60;

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
        return new RuleRequirements(counters: ['messages', 'media', 'stickers', 'forwards']);
    }

    public function check(AntispamMessageContext $context, EvaluationPlan $plan): ?AntiSpamDetection
    {
        $limit = $plan->paramOf(self::ID, self::DEFAULT_ACTIVITY_LIMIT);
        $total = $context->behavior->activityTotal5m;

        if ($total >= $limit) {
            return $this->detection(
                $plan,
                40,
                new DetectionDefaults(40, DetectionSeverity::Medium),
                "Activity flood: {$total} events in 5m >= {$limit}",
                ['activity' => $total],
            );
        }

        return null;
    }
}
