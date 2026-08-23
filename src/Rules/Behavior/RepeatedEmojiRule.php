<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Rules\Behavior;

use BAGArt\TelegramBotAntispam\Domain\AntiSpamDetection;
use BAGArt\TelegramBotAntispam\Domain\AntispamMessageContext;
use BAGArt\TelegramBotAntispam\Domain\EvaluationPlan;
use BAGArt\TelegramBotAntispam\Domain\DetectionSeverity;
use BAGArt\TelegramBotAntispam\Domain\RuleRequirements;
use BAGArt\TelegramBotAntispam\Rules\DetectionDefaults;

/** Emoji-only messages repeated (😂😂😂 spam). Requires text dominated by emoji. */
final class RepeatedEmojiRule extends RepeatedContentRule
{
    private const string ID = 'flood.repeat_emoji';
    private const int DEFAULT_REPEAT_LIMIT = 3;

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
        return new RuleRequirements(requiresText: true, counters: ['fingerprints']);
    }

    public function check(AntispamMessageContext $context, EvaluationPlan $plan): ?AntiSpamDetection
    {
        $text = $context->message->effectiveText();
        if ($text === null || ! $this->isEmojiOnly($text)) {
            return null;
        }

        $fingerprint = $this->fingerprint->of($text);
        if ($fingerprint === null) {
            return null;
        }

        $count = $context->behavior->fingerprints[$fingerprint] ?? 0;
        $limit = $plan->paramOf(self::ID, self::DEFAULT_REPEAT_LIMIT);

        if ($count >= $limit) {
            return $this->detection(
                $plan,
                20,
                new DetectionDefaults(20, DetectionSeverity::Info),
                "Repeated emoji-only message: {$count}x >= {$limit}",
                ['occurrences' => $count],
            );
        }

        return null;
    }

    private function isEmojiOnly(string $text): bool
    {
        $withoutEmoji = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2190}-\x{21FF}\x{2B00}-\x{2BFF}]/u', '', $text);

        return trim((string) $withoutEmoji) === '' && $text !== '';
    }
}
