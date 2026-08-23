<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Rules\Content;

use BAGArt\TelegramBotAntispam\Domain\AntiSpamDetection;
use BAGArt\TelegramBotAntispam\Domain\AntispamMessageContext;
use BAGArt\TelegramBotAntispam\Domain\BehaviorContext;
use BAGArt\TelegramBotAntispam\Domain\DetectionSeverity;
use BAGArt\TelegramBotAntispam\Domain\EvaluationPlan;
use BAGArt\TelegramBotAntispam\Domain\RuleRequirements;
use BAGArt\TelegramBotAntispam\Rules\AntiSpamRule;
use BAGArt\TelegramBotAntispam\Rules\DetectionDefaults;

/** Link rate flood: N links per minute (counted by ObservationCollector). */
final class LinkFloodRule extends AntiSpamRule
{
    private const string ID = 'advertising.link_flood';
    private const int DEFAULT_LIMIT = 5;

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
        return new RuleRequirements(requiresEntities: true, counters: ['links']);
    }

    public function check(AntispamMessageContext $context, EvaluationPlan $plan): ?AntiSpamDetection
    {
        $limit = $plan->paramOf(self::ID, self::DEFAULT_LIMIT);

        if ($this->rate($context->behavior) >= $limit) {
            return $this->detection(
                $plan,
                40,
                new DetectionDefaults(40, DetectionSeverity::Medium),
                "Link rate exceeded: {$context->behavior->links1m}/min >= {$limit}",
            );
        }

        return null;
    }

    private function rate(BehaviorContext $behavior): int
    {
        return $behavior->links1m;
    }
}
