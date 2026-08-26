<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Rules\Media;

use BAGArt\TelegramBotAntispam\Domain\AntiSpamDetection;
use BAGArt\TelegramBotAntispam\Domain\AntispamMessageContext;
use BAGArt\TelegramBotAntispam\Domain\DetectionKind;
use BAGArt\TelegramBotAntispam\Domain\DetectionSeverity;
use BAGArt\TelegramBotAntispam\Domain\EvaluationPlan;
use BAGArt\TelegramBotAntispam\Domain\RuleRequirements;
use BAGArt\TelegramBotAntispam\Rules\AntiSpamRule;
use BAGArt\TelegramBotAntispam\Rules\Content\AdvertisingRule;
use BAGArt\TelegramBotAntispam\Rules\DetectionDefaults;

/**
 * Advertising smuggled into media captions: photo/video/document/voice with
 * an ad-pattern caption. Fires only when the text body is absent — plain-text
 * ads are already covered by advertising.regex (no double counting).
 */
final class CaptionAdvertisingRule extends AntiSpamRule
{
    private const string ID = 'advertising.media_caption';

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
        return new RuleRequirements(requiresMedia: true);
    }

    public function check(AntispamMessageContext $context, EvaluationPlan $plan): ?AntiSpamDetection
    {
        $message = $context->message;
        if ($message->text !== null || $message->caption === null) {
            return null;
        }

        foreach (AdvertisingRule::PATTERNS as $pattern) {
            if (preg_match($pattern, $message->caption) === 1) {
                return $this->detection(
                    $plan,
                    70,
                    new DetectionDefaults(70, DetectionSeverity::High, DetectionKind::Hard),
                    'Advertising pattern in media caption',
                    ['pattern' => $pattern],
                );
            }
        }

        return null;
    }
}
