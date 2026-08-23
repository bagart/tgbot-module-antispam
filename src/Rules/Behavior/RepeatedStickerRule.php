<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Rules\Behavior;

use BAGArt\TelegramBotAntispam\Domain\AntiSpamDetection;
use BAGArt\TelegramBotAntispam\Domain\AntispamMessageContext;
use BAGArt\TelegramBotAntispam\Domain\EvaluationPlan;
use BAGArt\TelegramBotAntispam\Domain\DetectionSeverity;
use BAGArt\TelegramBotAntispam\Domain\RuleRequirements;
use BAGArt\TelegramBotAntispam\Rules\DetectionDefaults;

/** Sticker flood: same sticker repeated (fingerprint) or sticker rate per minute. */
final class RepeatedStickerRule extends RepeatedContentRule
{
    private const string ID = 'flood.repeat_sticker';
    private const int DEFAULT_STICKER_LIMIT = 4;

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
        return new RuleRequirements(requiresSticker: true, counters: ['fingerprints', 'stickers']);
    }

    public function check(AntispamMessageContext $context, EvaluationPlan $plan): ?AntiSpamDetection
    {
        if (! $context->message->hasSticker) {
            return null;
        }

        $limit = $plan->paramOf(self::ID, self::DEFAULT_STICKER_LIMIT);

        // Exact identity via file_unique_id fingerprint (recorded by the collector)
        if ($context->message->mediaFileId !== null) {
            $fingerprint = hash('sha256', 'file:'.$context->message->mediaFileId);
            $count = $context->behavior->fingerprints[$fingerprint] ?? 0;
            if ($count >= $limit) {
                return $this->detection(
                    $plan,
                    30,
                    new DetectionDefaults(30, DetectionSeverity::Low),
                    "Same sticker {$count}x within window >= {$limit}",
                    ['occurrences' => $count, 'by' => 'file_id'],
                );
            }
        }

        // Fallbacks: emoji-identity and per-minute sticker rate
        $emoji = $context->message->stickerEmoji;
        if ($emoji !== null) {
            $fingerprint = hash('sha256', 'sticker:'.$emoji);
            $count = $context->behavior->fingerprints[$fingerprint] ?? 0;
            if ($count >= $limit) {
                return $this->detection(
                    $plan,
                    30,
                    new DetectionDefaults(30, DetectionSeverity::Low),
                    "Same sticker {$count}x within window >= {$limit}",
                    ['occurrences' => $count, 'by' => 'emoji'],
                );
            }
        }

        $rate = $context->behavior->stickers1m;
        if ($rate >= $limit + 2) {
            return $this->detection(
                $plan,
                20,
                new DetectionDefaults(20, DetectionSeverity::Info),
                "Sticker rate: {$rate}/min",
                ['occurrences' => $rate],
            );
        }

        return null;
    }
}
