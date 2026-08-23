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

/** Combined sticker+emoji spam rate: sticker wall / emoji wall as one signal. */
final class StickerEmojiFloodRule extends AntiSpamRule
{
    private const string ID = 'flood.sticker_emoji';
    private const int DEFAULT_LIMIT = 8;

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
        return new RuleRequirements(counters: ['stickers']);
    }

    public function check(AntispamMessageContext $context, EvaluationPlan $plan): ?AntiSpamDetection
    {
        $limit = $plan->paramOf(self::ID, self::DEFAULT_LIMIT);
        $rate = $context->behavior->stickers1m;

        if ($rate >= $limit) {
            return $this->detection(
                $plan,
                30,
                new DetectionDefaults(30, DetectionSeverity::Low),
                "Sticker/emoji flood: {$rate}/min >= {$limit}",
            );
        }

        return null;
    }
}
