<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Rules\Content;

use BAGArt\TelegramBotAntispam\Domain\AntiSpamDetection;
use BAGArt\TelegramBotAntispam\Domain\AntispamMessageContext;
use BAGArt\TelegramBotAntispam\Domain\DetectionSeverity;
use BAGArt\TelegramBotAntispam\Domain\EvaluationPlan;
use BAGArt\TelegramBotAntispam\Domain\RuleRequirements;
use BAGArt\TelegramBotAntispam\Rules\AntiSpamRule;
use BAGArt\TelegramBotAntispam\Rules\DetectionDefaults;

/** Mention rate flood: mass-pinging of users (@mentions) per minute. */
final class MentionFloodRule extends AntiSpamRule
{
    private const string ID = 'advertising.mention_flood';
    private const int DEFAULT_LIMIT = 10;

    public function id(): string
    {
        return self::ID;
    }

    public function group(): string
    {
        return 'advertising';
    }

    public function requirements(): RuleRequirements
    {
        return new RuleRequirements(requiresEntities: true, counters: ['mentions']);
    }

    public function check(AntispamMessageContext $context, EvaluationPlan $plan): ?AntiSpamDetection
    {
        $limit = $plan->paramOf(self::ID, self::DEFAULT_LIMIT);
        $rate = $context->behavior->mentions1m;

        if ($rate >= $limit) {
            return $this->detection(
                $plan,
                20,
                new DetectionDefaults(20, DetectionSeverity::Low),
                "Mention rate exceeded: {$rate}/min >= {$limit}",
            );
        }

        return null;
    }
}
