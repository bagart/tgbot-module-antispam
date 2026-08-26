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
        private ?\BAGArt\AsyncKernel\Wrappers\ASKLogWrapper $logger = null,
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
            $this->publishToBlocklistFeed($violation);

            return;
        }

        $this->sender->send($botConfig, new RestrictChatMemberMethodDTO(
            chatId: (string) $violation->chat_id,
            userId: $violation->user_id,
            permissions: new ChatPermissionsTypeDTO(), // all null = fully muted
            untilDate: $this->restrictUntil($violation),
        ));
    }

    /**
     * Federated blocklist publishing (P3.7): every ban lands in the platform
     * feed so subscriber bots can ingest it via antispam:blocklist:sync.
     * Best-effort — a feed failure never blocks enforcement.
     */
    private function publishToBlocklistFeed(AntispamViolation $violation): void
    {
        try {
            \BAGArt\TelegramBotAntispam\Models\AntispamBlocklistFeed::query()->updateOrCreate([
                'source_bot_id' => $violation->bot_id,
                'user_id' => $violation->user_id,
            ], [
                'reason' => implode(', ', array_column((array) $violation->matched_rules, 'ruleId')),
                'published_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $this->logger?->warning('antispam: blocklist feed publish failed', [
                'botId' => $violation->bot_id,
                'userId' => $violation->user_id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
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
