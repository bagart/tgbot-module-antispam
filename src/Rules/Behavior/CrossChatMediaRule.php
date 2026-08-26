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
 * Cross-chat media relay: the same file (file_unique_id identity) surfaced in
 * several chats of one bot within the window — a hallmark of spam relays that
 * rotate accounts. The bot-wide dimension comes from the batch counter
 * ("xmchats:" counts, distinct chatIds per media hash).
 */
final class CrossChatMediaRule extends AntiSpamRule
{
    private const string ID = 'flood.media_cross_chat';
    private const int DEFAULT_CHAT_LIMIT = 3;

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
        return new RuleRequirements(requiresMedia: true, counters: ['cross_chat']);
    }

    public function check(AntispamMessageContext $context, EvaluationPlan $plan): ?AntiSpamDetection
    {
        $fileId = $context->message->mediaFileId;
        if ($fileId === null) {
            return null;
        }

        $limit = $plan->paramOf(self::ID, self::DEFAULT_CHAT_LIMIT);
        $fingerprint = hash('sha256', 'xchat:'.$fileId);
        $chats = $context->behavior->crossChatMedia[$fingerprint] ?? 0;
        if ($chats < $limit) {
            return null;
        }

        return $this->detection(
            $plan,
            40,
            new DetectionDefaults(40, DetectionSeverity::Medium),
            "Same media seen in {$chats} chats >= {$limit}",
            ['distinctChats' => $chats],
        );
    }
}
