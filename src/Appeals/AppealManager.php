<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Appeals;

use BAGArt\TelegramBotAntispam\Models\AntispamAppeal;
use BAGArt\TelegramBotAntispam\Models\AntispamViolation;

/**
 * Files /appeal requests against the user's latest sanction in the chat.
 * Only applied restrict/ban sanctions are appealable.
 */
final readonly class AppealManager
{
    private const SANCTION_ACTIONS = ['restrict', 'ban'];

    private const APPEALABLE_STATUSES = [
        AntispamViolation::STATUS_APPLIED,
        AntispamViolation::STATUS_ESCALATED,
    ];

    public function file(string $botId, int $chatId, int $userId, ?string $reason): AppealFilingOutcome
    {
        $violation = AntispamViolation::query()
            ->where('bot_id', $botId)
            ->where('chat_id', $chatId)
            ->where('user_id', $userId)
            ->latest()
            ->first();

        if (
            $violation === null
            || !in_array($violation->enforcement_action, self::SANCTION_ACTIONS, true)
            || !in_array($violation->status, self::APPEALABLE_STATUSES, true)
        ) {
            return AppealFilingOutcome::NoActiveSanction;
        }

        $pendingExists = AntispamAppeal::query()
            ->where('violation_id', $violation->id)
            ->where('user_id', $userId)
            ->where('status', AntispamAppeal::STATUS_PENDING)
            ->exists();

        if ($pendingExists) {
            return AppealFilingOutcome::DuplicatePending;
        }

        AntispamAppeal::create([
            'violation_id' => $violation->id,
            'user_id' => $userId,
            'message' => $reason,
            'status' => AntispamAppeal::STATUS_PENDING,
        ]);

        return AppealFilingOutcome::Created;
    }
}
