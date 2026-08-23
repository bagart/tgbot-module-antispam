<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Rules\Content;

use BAGArt\TelegramBotAntispam\Domain\AntiSpamDetection;
use BAGArt\TelegramBotAntispam\Domain\AntispamMessageContext;
use BAGArt\TelegramBotAntispam\Domain\DetectionKind;
use BAGArt\TelegramBotAntispam\Domain\DetectionSeverity;
use BAGArt\TelegramBotAntispam\Domain\EvaluationPlan;
use BAGArt\TelegramBotAntispam\Domain\RuleRequirements;
use BAGArt\TelegramBotAntispam\Rules\AntiSpamRule;
use BAGArt\TelegramBotAntispam\Rules\DetectionDefaults;

/** Advertising patterns: promo phrases, url shorteners, spam clichés. */
final class AdvertisingRule extends AntiSpamRule
{
    private const string ID = 'advertising.regex';

    /** @var list<string> */
    private const array PATTERNS = [
        '/(?:t\.me|telegram\.me|bit\.ly|cutt\.ly|tinyurl|is\.gd|shorturl)\S+/iu',
        '/(?:заработок|заработать)\s+(?:на|в|с)\s+/ui',
        '/(?:инвестици\w+|криптовалют\w+|трейдинг)/ui',
        '/(?:партн(?:ё|е)рк\w*|промо\s?код|реклама\s+в\s+(?:лс|личку))/ui',
        '/(?:casino|казино|букмекер\w*)/ui',
        '/\$[0-9]+\s?(?:per|for)\s?(?:hour|day|week)/i',
    ];

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

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return $this->detection(
                    $plan,
                    60,
                    new DetectionDefaults(60, DetectionSeverity::High, DetectionKind::Hard),
                    'Advertising pattern matched',
                    ['pattern' => $pattern],
                );
            }
        }

        return null;
    }
}
