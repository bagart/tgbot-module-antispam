<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Moderation;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\TgApi\Methods\DTO\BanChatMemberMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\DTO\DeleteMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\DTO\RestrictChatMemberMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\DTO\UnbanChatMemberMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatPermissionsTypeDTO;
use BAGArt\TelegramBotAntispam\Enforcement\ActionExecutor;
use BAGArt\TelegramBotAntispam\Models\AntispamAppeal;
use BAGArt\TelegramBotAntispam\Models\AntispamStrikeEvent;
use BAGArt\TelegramBotAntispam\Models\AntispamViolation;

/**
 * Shared decision point for appeals and the moderation queue.
 * Idempotent: only status-eligible violations transition, repeated
 * calls are no-ops (false). Guard is model-state based, mirroring
 * ActionExecutor's "idempotent on Telegram's side" contract.
 */
final readonly class AntispamModerationService
{
    public function __construct(
        private TgSenderContract $sender,
        private ActionExecutor $executor,
    ) {
    }

    public function decideAppeal(
        AntispamAppeal $appeal,
        bool $approve,
        string $decidedBy,
        TgBotConfig $botConfig,
    ): bool {
        if ($appeal->status !== AntispamAppeal::STATUS_PENDING) {
            return false;
        }

        if ($approve) {
            $this->overturn($appeal->violation, $botConfig);
            $appeal->status = AntispamAppeal::STATUS_APPROVED;
        } else {
            $appeal->status = AntispamAppeal::STATUS_REJECTED;
        }

        $appeal->decided_by = $decidedBy;
        $appeal->decided_at = now();
        $appeal->save();

        return true;
    }

    /**
     * Executes the recorded enforcement action for a pending violation
     * (delete/restrict/ban/warn) and marks it applied.
     */
    public function applyViolation(AntispamViolation $violation, TgBotConfig $botConfig): bool
    {
        if ($violation->status !== AntispamViolation::STATUS_PENDING) {
            return false;
        }

        $this->executor->execute($violation, $botConfig);

        return true;
    }

    /**
     * Overturns a pending/applied/escalated violation. Active sanctions
     * (restrict/ban from applied or escalated states) are lifted; a pending
     * violation has nothing to lift and transitions silently.
     */
    public function overturn(AntispamViolation $violation, TgBotConfig $botConfig): bool
    {
        if (! in_array($violation->status, [AntispamViolation::STATUS_PENDING, AntispamViolation::STATUS_APPLIED, AntispamViolation::STATUS_ESCALATED], true)) {
            return false;
        }

        if ($violation->status !== AntispamViolation::STATUS_PENDING) {
            $this->liftSanctions($violation, $botConfig);
        }

        $violation->status = AntispamViolation::STATUS_OVERTURNED;
        $violation->save();

        return true;
    }

    /**
     * Steps one rung up the sanction ladder (warn/delete → restrict → ban)
     * and marks the violation escalated. A banned user cannot escalate further.
     */
    public function escalate(AntispamViolation $violation, TgBotConfig $botConfig): bool
    {
        if (! in_array($violation->status, [AntispamViolation::STATUS_PENDING, AntispamViolation::STATUS_APPLIED], true)) {
            return false;
        }

        $nextAction = match ($violation->enforcement_action) {
            'ban' => null,
            'restrict' => 'ban',
            default => 'restrict',
        };

        if ($nextAction === null) {
            return false;
        }

        $this->sender->send($botConfig, new DeleteMessageMethodDTO(
            chatId: (string) $violation->chat_id,
            messageId: $violation->message_id,
        ));

        if ($nextAction === 'ban') {
            $this->sender->send($botConfig, new BanChatMemberMethodDTO(
                chatId: (string) $violation->chat_id,
                userId: $violation->user_id,
            ));
        } else {
            $this->sender->send($botConfig, new RestrictChatMemberMethodDTO(
                chatId: (string) $violation->chat_id,
                userId: $violation->user_id,
                permissions: new ChatPermissionsTypeDTO(), // all null = fully muted
                untilDate: $this->restrictUntil($violation),
            ));
        }

        $violation->enforcement_action = $nextAction;
        $violation->status = AntispamViolation::STATUS_ESCALATED;
        $violation->save();

        return true;
    }

    private function liftSanctions(AntispamViolation $violation, TgBotConfig $botConfig): void
    {
        match ($violation->enforcement_action) {
            'ban' => $this->sender->send($botConfig, new UnbanChatMemberMethodDTO(
                chatId: (string) $violation->chat_id,
                userId: $violation->user_id,
                onlyIfBanned: true,
            )),
            'restrict' => $this->sender->send($botConfig, new RestrictChatMemberMethodDTO(
                chatId: (string) $violation->chat_id,
                userId: $violation->user_id,
                permissions: new ChatPermissionsTypeDTO(
                    canSendMessages: true,
                    canSendAudios: true,
                    canSendDocuments: true,
                    canSendPhotos: true,
                    canSendVideos: true,
                    canSendVideoNotes: true,
                    canSendVoiceNotes: true,
                    canSendPolls: true,
                    canSendOtherMessages: true,
                    canAddWebPagePreviews: true,
                ),
            )),
            default => null,
        };
    }

    private function restrictUntil(AntispamViolation $violation): ?int
    {
        $event = AntispamStrikeEvent::query()
            ->where('violation_id', $violation->id)
            ->first();

        return $event?->expired_at?->getTimestamp();
    }
}
