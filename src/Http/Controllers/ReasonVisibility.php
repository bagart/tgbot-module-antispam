<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Http\Controllers;

use BAGArt\TelegramBotAntispam\Auth\T2Gate;
use BAGArt\TelegramBotAntispam\Models\AntispamUserListEntry;
use Illuminate\Http\Request;

/**
 * Per-row capability visibility for the moderation list controllers (D14/D15).
 *
 * A request never renders a stored "reason" for a chat the viewer lacks
 * moderation.reason.view in, nor a moderation-history event for a chat the
 * viewer lacks moderation.log.view in: the field is emptied
 * (violations/appeals) or nulled (list entries) or the event dropped
 * (history) while rule ids stay — the queue must remain browsable.
 * Memoized per capability|bot|chat on the controller instance, which is
 * request-scoped, so no stale grants survive the request.
 */
trait ReasonVisibility
{
    /** @var array<string, bool> */
    private array $capabilityVisible = [];

    private function reasonsVisible(?T2Gate $gate, Request $request, string $botId, int $chatId): bool
    {
        return $this->capabilityAllowed($gate, $request, T2Gate::CAPABILITY, $botId, $chatId);
    }

    private function capabilityAllowed(
        ?T2Gate $gate,
        Request $request,
        string $capability,
        string $botId,
        int $chatId,
    ): bool {
        if ($gate === null) {
            return true;
        }

        $key = $capability.'|'.$botId.'|'.$chatId;
        if (isset($this->capabilityVisible[$key])) {
            return $this->capabilityVisible[$key];
        }

        $user = $request->user();
        $telegramId = $user?->telegram_id;

        return $this->capabilityVisible[$key] = $gate->canView(
            capability: $capability,
            botId: $botId,
            chatId: $chatId,
            platformUserId: (int) ($user->id ?? 0),
            telegramUserId: $telegramId === null ? null : (int) $telegramId,
        );
    }

    /**
     * Matched rules for one violation row, "reason" emptied when the viewer
     * may not see reasons in that chat.
     *
     * @param  array<array<string, mixed>>  $matchedRules
     * @return array<array<string, mixed>>
     */
    private function visibleMatchedRules(?T2Gate $gate, Request $request, string $botId, int $chatId, array $matchedRules): array
    {
        if ($this->reasonsVisible($gate, $request, $botId, $chatId)) {
            return $matchedRules;
        }

        return array_map(static fn (array $rule): array => [...$rule, 'reason' => ''], $matchedRules);
    }

    /**
     * Evaluation snapshot with the embedded matched-rule reasons emptied —
     * it duplicates matched_rules[] and ships to the browser in props.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function visibleEvaluationSnapshot(?T2Gate $gate, Request $request, string $botId, int $chatId, array $snapshot): array
    {
        $matched = $snapshot['matchedRules'] ?? null;
        if (! is_array($matched)) {
            return $snapshot;
        }

        $snapshot['matchedRules'] = $this->visibleMatchedRules($gate, $request, $botId, $chatId, $matched);

        return $snapshot;
    }

    /**
     * One user-list row with its reason nulled when the viewer may not see
     * reasons in that chat; the original model stays untouched.
     */
    private function visibleListEntry(?T2Gate $gate, Request $request, AntispamUserListEntry $entry): AntispamUserListEntry
    {
        if ($this->reasonsVisible($gate, $request, (string) $entry->bot_id, (int) $entry->chat_id)) {
            return $entry;
        }

        $redacted = clone $entry;
        $redacted->reason = null;

        return $redacted;
    }
}
