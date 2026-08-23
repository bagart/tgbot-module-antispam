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
 * Character flood: same character dominates the text (AAAA…, !!!!!).
 * Fires only when ratio ≥ X AND length ≥ Y — a long normal message is safe.
 */
final class CharacterFloodRule extends AntiSpamRule
{
    private const string ID = 'flood.character';
    private const int DEFAULT_MIN_LENGTH = 20;
    private const float DEFAULT_SAME_CHARACTER_RATIO = 0.7;

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
        $text = $context->message->effectiveText();
        if ($text === null) {
            return null;
        }

        $minLength = $plan->paramOf(self::ID, self::DEFAULT_MIN_LENGTH);
        if (mb_strlen($text) < $minLength) {
            return null;
        }

        if ($this->sameCharacterRatio($text) < self::DEFAULT_SAME_CHARACTER_RATIO) {
            return null;
        }

        return $this->detection(
            $plan,
            30,
            new DetectionDefaults(30, DetectionSeverity::Low),
            'Character flood: single dominant character',
        );
    }

    private function sameCharacterRatio(string $text): float
    {
        $clean = preg_replace('/\s/u', '', $text) ?? '';
        $length = mb_strlen($clean);
        if ($length === 0) {
            return 0.0;
        }

        $counts = [];
        foreach (mb_str_split($clean) as $char) {
            $counts[$char] = ($counts[$char] ?? 0) + 1;
        }

        return max($counts) / $length;
    }
}
