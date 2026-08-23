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

/** Email addresses and phone numbers in text — classic mass-DM contact harvesting. */
final class PhoneEmailRule extends AntiSpamRule
{
    private const string ID = 'advertising.contact';

    private const string EMAIL = '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i';
    private const string PHONE = '/(?:\+?\d[\s\-()]{0,2}){7,12}\d/u';

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
        return new RuleRequirements(requiresText: true);
    }

    public function check(AntispamMessageContext $context, EvaluationPlan $plan): ?AntiSpamDetection
    {
        $text = $context->message->effectiveText();
        if ($text === null) {
            return null;
        }

        if (preg_match(self::EMAIL, $text, $m) === 1) {
            return $this->detection(
                $plan,
                30,
                new DetectionDefaults(30, DetectionSeverity::Medium),
                'Contact info in message (email)',
                ['kind' => 'email'],
            );
        }

        if (preg_match(self::PHONE, $text) === 1 && $this->looksLikePhone($text)) {
            return $this->detection(
                $plan,
                20,
                new DetectionDefaults(20, DetectionSeverity::Low),
                'Contact info in message (phone)',
                ['kind' => 'phone'],
            );
        }

        return null;
    }

    /** Digit-density guard so years/counts are not flagged as phones. */
    private function looksLikePhone(string $text): bool
    {
        preg_match_all('/\d/', $text, $digits);
        $digitCount = count($digits[0] ?? []);

        return $digitCount >= 7 && mb_strlen($text) <= 120;
    }
}
