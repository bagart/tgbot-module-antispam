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
 * Animated-emoji flood: the same custom emoji (premium animated emoji) repeated
 * within the fingerprint window. The ObservationCollector records one
 * "custom_emoji:{id}" fingerprint per distinct id; this rule reads it back.
 */
final class AnimatedEmojiFloodRule extends AntiSpamRule
{
    private const string ID = 'flood.animated_emoji';
    private const int DEFAULT_REPEAT_LIMIT = 5;

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
        return new RuleRequirements(requiresEntities: true, counters: ['fingerprints']);
    }

    public function check(AntispamMessageContext $context, EvaluationPlan $plan): ?AntiSpamDetection
    {
        $limit = $plan->paramOf(self::ID, self::DEFAULT_REPEAT_LIMIT);

        foreach ($this->customEmojiIds($context) as $id) {
            $fingerprint = hash('sha256', 'custom_emoji:'.$id);
            $count = $context->behavior->fingerprints[$fingerprint] ?? 0;
            if ($count >= $limit) {
                return $this->detection(
                    $plan,
                    25,
                    new DetectionDefaults(25, DetectionSeverity::Low),
                    "Animated emoji {$id} sent {$count}x within window >= {$limit}",
                    ['customEmojiId' => $id, 'occurrences' => $count],
                );
            }
        }

        return null;
    }

    /** @return list<string> */
    private function customEmojiIds(AntispamMessageContext $context): array
    {
        $ids = [];
        foreach ($context->message->entities ?? [] as $entity) {
            if (($entity['type'] ?? '') === 'custom_emoji' && isset($entity['custom_emoji_id'])) {
                $ids[$entity['custom_emoji_id']] = true;
            }
        }

        return array_keys($ids);
    }
}
