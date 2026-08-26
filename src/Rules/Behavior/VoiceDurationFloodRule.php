<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Rules\Behavior;

use BAGArt\TelegramBotAntispam\Domain\AntiSpamDetection;
use BAGArt\TelegramBotAntispam\Domain\AntispamMessageContext;
use BAGArt\TelegramBotAntispam\Domain\DetectionSeverity;
use BAGArt\TelegramBotAntispam\Domain\EvaluationPlan;
use BAGArt\TelegramBotAntispam\Domain\RuleRequirements;
use BAGArt\TelegramBotAntispam\Rules\AntiSpamRule;
use BAGArt\TelegramBotAntispam\Rules\DetectionDefaults;

/**
 * Long-voice flood: repeated voice messages above a duration threshold within
 * the 30s window (voice spam / raid tooling). Absent duration metadata means
 * "no detection" — never a signal.
 */
final class VoiceDurationFloodRule extends AntiSpamRule
{
    private const string ID = 'flood.voice30s';
    private const int DEFAULT_LIMIT = 3;
    private const int DEFAULT_MIN_DURATION = 30;

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
        return new RuleRequirements(requiresMedia: true, counters: ['voices']);
    }

    public function check(AntispamMessageContext $context, EvaluationPlan $plan): ?AntiSpamDetection
    {
        $message = $context->message;
        if ($message->mediaKind !== 'voice' || $message->mediaDurationSeconds === null) {
            return null;
        }

        $minDuration = $plan->paramOf(self::ID.'.min_duration', self::DEFAULT_MIN_DURATION);
        if ($message->mediaDurationSeconds < $minDuration) {
            return null;
        }

        $limit = $plan->paramOf(self::ID, self::DEFAULT_LIMIT);
        $rate = $context->behavior->voices30s;
        if ($rate < $limit) {
            return null;
        }

        return $this->detection(
            $plan,
            35,
            new DetectionDefaults(35, DetectionSeverity::Low),
            "Long voice flood: {$rate} voices >= {$limit} in 30s (duration {$message->mediaDurationSeconds}s)",
            ['occurrences' => $rate, 'durationSeconds' => $message->mediaDurationSeconds],
        );
    }
}
