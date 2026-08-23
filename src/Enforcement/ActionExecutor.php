<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Enforcement;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\TgApi\Methods\DTO\BanChatMemberMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\DTO\DeleteMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\DTO\RestrictChatMemberMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatPermissionsTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\ReplyParametersTypeDTO;
use BAGArt\TelegramBotAntispam\Models\AntispamStrikeEvent;
use BAGArt\TelegramBotAntispam\Models\AntispamViolation;
use BAGArt\TelegramBotAntispam\Violation\ViolationRecorder;

/**
 * Applies enforcement through TgSenderContract → outbound pipeline (async,
 * webhook latency independent of the network). Idempotent by construction:
 * deleting an already-deleted message / banning an already-banned user is a
 * no-op on Telegram's side, and outbound retries are safe.
 */
final readonly class ActionExecutor
{
    public function __construct(
        private TgSenderContract $sender,
        private ViolationRecorder $recorder,
    ) {
    }

    public function execute(AntispamViolation $violation, TgBotConfig $botConfig): void
    {
        match ($violation->enforcement_action) {
            'delete' => $this->sender->send($botConfig, new DeleteMessageMethodDTO(
                chatId: (string) $violation->chat_id,
                messageId: $violation->message_id,
            )),
            // restrict/ban always remove the offending message too
            'restrict', 'ban' => $this->restrictOrBan($violation, $botConfig),
            default => $this->warn($violation, $botConfig),
        };

        $this->recorder->markApplied($violation);
    }

    private function restrictOrBan(AntispamViolation $violation, TgBotConfig $botConfig): void
    {
        $this->sender->send($botConfig, new DeleteMessageMethodDTO(
            chatId: (string) $violation->chat_id,
            messageId: $violation->message_id,
        ));

        if ($violation->enforcement_action === 'ban') {
            $this->sender->send($botConfig, new BanChatMemberMethodDTO(
                chatId: (string) $violation->chat_id,
                userId: $violation->user_id,
            ));

            return;
        }

        $this->sender->send($botConfig, new RestrictChatMemberMethodDTO(
            chatId: (string) $violation->chat_id,
            userId: $violation->user_id,
            permissions: new ChatPermissionsTypeDTO(), // all null = fully muted
            untilDate: $this->restrictUntil($violation),
        ));
    }

    private function restrictUntil(AntispamViolation $violation): ?int
    {
        $event = AntispamStrikeEvent::query()
            ->where('violation_id', $violation->id)
            ->first();

        return $event?->expired_at?->getTimestamp();
    }

    private function warn(AntispamViolation $violation, TgBotConfig $botConfig): void
    {
        $rules = implode(', ', array_column((array) $violation->matched_rules, 'ruleId'));

        $this->sender->send($botConfig, new SendMessageMethodDTO(
            chatId: (string) $violation->chat_id,
            text: "⚠️ Spam signals detected ({$rules}). Further violations lead to restrictions.",
            replyParameters: new ReplyParametersTypeDTO(
                messageId: $violation->message_id,
                chatId: (string) $violation->chat_id,
            ),
        ));
    }
}
