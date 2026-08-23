<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Rules\Behavior;

use BAGArt\TelegramBotAntispam\Domain\AntiSpamDetection;
use BAGArt\TelegramBotAntispam\Domain\AntispamMessageContext;
use BAGArt\TelegramBotAntispam\Domain\EvaluationPlan;
use BAGArt\TelegramBotAntispam\Domain\RuleRequirements;
use BAGArt\TelegramBotAntispam\Counters\MessageFingerprint;
use BAGArt\TelegramBotAntispam\Rules\DetectionDefaults;

/**
 * Repeated identical media: same file_unique_id within the fingerprint window.
 * Rate-based fallback (media30s) catches bursts of different media.
 */
final class RepeatedMediaRule extends RepeatedContentRule
{
    private const string ID = 'flood.repeat_media';
    private const int DEFAULT_REPEAT_LIMIT = 3;
    private const int DEFAULT_RATE_LIMIT = 4;

    public function __construct(MessageFingerprint $fingerprint = new MessageFingerprint())
    {
        parent::__construct($fingerprint);
    }

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
        return new RuleRequirements(requiresMedia: true, counters: ['media', 'fingerprints']);
    }

    public function check(AntispamMessageContext $context, EvaluationPlan $plan): ?AntiSpamDetection
    {
        if (! $context->message->hasMedia) {
            return null;
        }

        // Exact identity: same file resent repeatedly
        if ($context->message->mediaFileId !== null) {
            $fingerprint = hash('sha256', 'file:'.$context->message->mediaFileId);
            $count = $context->behavior->fingerprints[$fingerprint] ?? 0;
            $limit = $plan->paramOf(self::ID, self::DEFAULT_REPEAT_LIMIT);

            if ($count >= $limit) {
                return $this->detection(
                    $plan,
                    40,
                    new DetectionDefaults(40, \BAGArt\TelegramBotAntispam\Domain\DetectionSeverity::Medium),
                    "Same media {$count}x within window >= {$limit}",
                    ['occurrences' => $count, 'by' => 'file_id'],
                );
            }
        }

        // Burst of different media
        $rateLimit = $plan->paramOf(self::ID.'.rate', self::DEFAULT_RATE_LIMIT);
        $rate = $context->behavior->media30s;
        if ($rate >= $rateLimit) {
            return $this->detection(
                $plan,
                30,
                new DetectionDefaults(30, \BAGArt\TelegramBotAntispam\Domain\DetectionSeverity::Low),
                "Media burst: {$rate} in 30s >= {$rateLimit}",
                ['occurrences' => $rate, 'by' => 'rate'],
            );
        }

        return null;
    }
}
