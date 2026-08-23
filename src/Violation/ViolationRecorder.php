<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Violation;

use BAGArt\TelegramBotAntispam\Domain\AggregatedScore;
use BAGArt\TelegramBotAntispam\Domain\AntiSpamVerdict;
use BAGArt\TelegramBotAntispam\Domain\EnforcementAction;
use BAGArt\TelegramBotAntispam\Domain\EvaluationSnapshot;
use BAGArt\TelegramBotAntispam\Domain\MessageData;
use BAGArt\TelegramBotAntispam\Domain\RiskContext;
use BAGArt\TelegramBotAntispam\Models\AntispamViolation;

/**
 * Persists final-verdict-only violations, idempotent per
 * UNIQUE(bot_id, chat_id, message_id). Webhook retries never duplicate.
 *
 * @return array{0: AntispamViolation, 1: bool} violation + created flag
 */
final readonly class ViolationRecorder
{
    public function record(
        string $botId,
        int $chatId,
        int $userId,
        MessageData $message,
        AggregatedScore $score,
        AntiSpamVerdict $verdict,
        EvaluationSnapshot $snapshot,
        ?RiskContext $risk = null,
        ?EnforcementAction $overrideAction = null,
    ): array {
        $existing = AntispamViolation::query()
            ->where('bot_id', $botId)
            ->where('chat_id', $chatId)
            ->where('message_id', $message->messageId)
            ->first();
        if ($existing !== null) {
            return [$existing, false];
        }

        $violation = AntispamViolation::create([
            'bot_id' => $botId,
            'chat_id' => $chatId,
            'user_id' => $userId,
            'message_id' => $message->messageId,
            'message_snapshot' => [
                'messageId' => $message->messageId,
                'text' => $message->text,
                'caption' => $message->caption,
                'hasMedia' => $message->hasMedia,
                'mediaKind' => $message->mediaKind,
                'mediaFileId' => $message->mediaFileId,
                'hasSticker' => $message->hasSticker,
                'isForwarded' => $message->isForwarded,
                'isReply' => $message->isReply,
                'date' => $message->date->format(DATE_ATOM),
            ],
            'matched_rules' => array_map(
                static fn ($d): array => [
                    'ruleId' => $d->ruleId,
                    'score' => $d->score,
                    'severity' => $d->severity->value,
                    'kind' => $d->kind->value,
                    'group' => $d->group,
                    'reason' => $d->reason,
                    'metadata' => $d->metadata,
                ],
                $score->detections,
            ),
            'group_breakdown' => $score->groupBreakdown,
            'risk_context' => $risk === null ? null : [
                'level' => $risk->level,
                'previousViolations' => $risk->previousViolations,
                'riskVersion' => $risk->riskVersion,
            ],
            'evaluation_snapshot' => $snapshot->toArray(),
            'score' => $verdict->score,
            'verdict' => [
                'action' => $verdict->action->value,
                'reason' => $verdict->reason,
                'policyVersion' => $verdict->policyVersion,
                'thresholds' => $verdict->thresholds,
            ],
            'enforcement_action' => ($overrideAction ?? $verdict->action)->value,
            'status' => AntispamViolation::STATUS_PENDING,
        ]);

        return [$violation, true];
    }

    public function markApplied(AntispamViolation $violation): void
    {
        $violation->status = AntispamViolation::STATUS_APPLIED;
        $violation->save();
    }
}
