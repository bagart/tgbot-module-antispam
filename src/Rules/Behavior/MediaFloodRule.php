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

/** Media rate flood: N media messages within the 30s window. */
final class MediaFloodRule extends AntiSpamRule
{
    private const string ID = 'flood.media';
    private const int DEFAULT_MEDIA_LIMIT = 6;

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
        return new RuleRequirements(requiresMedia: true, counters: ['media']);
    }

    public function check(AntispamMessageContext $context, EvaluationPlan $plan): ?AntiSpamDetection
    {
        if (! $context->message->hasMedia) {
            return null;
        }

        $limit = $plan->paramOf(self::ID, self::DEFAULT_MEDIA_LIMIT);
        $count = $context->behavior->media30s;

        if ($count >= $limit) {
            return $this->detection(
                $plan,
                30,
                new DetectionDefaults(30, DetectionSeverity::Low),
                "Media flood: {$count} in 30s >= {$limit}",
                ['occurrences' => $count],
            );
        }

        return null;
    }
}
