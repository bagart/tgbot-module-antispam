<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Auth;

use BAGArt\TelegramBotAccess\AccessControlContract;
use BAGArt\TelegramBotAccess\AccessRequest;
use Illuminate\Support\Facades\DB;

/**
 * T2 capability gates (D14/D15): stored moderation reasons
 * (violation matched rules, appeal rules, list-entry reasons) and the
 * per-user moderation history are shown only to subjects holding the
 * matching capability for the row's chat scope.
 *
 * The only reason and log surfaces are the web moderation pages, so the
 * viewer is a platform user and the subject id arrives with the request —
 * unlike the mafia game gate there is no telegram-id lookup to do.
 *
 * Legacy fallback (decided together with mafia game.initiate): a viewer
 * without a telegram link (break-glass/email account, D15) keeps today's
 * behavior and sees everything; linked accounts are decided deny-by-default,
 * failing closed when the access layer errors.
 */
final class T2Gate
{
    public const string CAPABILITY = 'moderation.reason.view';

    public const string LOG_CAPABILITY = 'moderation.log.view';

    /** @var \Closure(int): ?int  Telegram chat id -> owning workspace id. */
    private readonly \Closure $workspaceIdOf;

    /**
     * @param  (\Closure(int): ?int)|null  $workspaceIdOf  Overrides the default
     *        tg_entities chat -> workspace lookup.
     */
    public function __construct(
        private readonly AccessControlContract $access,
        ?\Closure $workspaceIdOf = null,
    ) {
        $this->workspaceIdOf = $workspaceIdOf ?? self::workspaceResolver();
    }

    /**
     * Decision for one of this module's T2 capabilities (CAPABILITY,
     * LOG_CAPABILITY); the caller memoizes per capability|bot|chat.
     */
    public function canView(
        string $capability,
        string $botId,
        int $chatId,
        int $platformUserId,
        ?int $telegramUserId,
    ): bool {
        if ($telegramUserId === null) {
            return true;
        }

        try {
            return $this->access->decide(new AccessRequest(
                botId: $botId,
                subjectId: (string) $platformUserId,
                capability: $capability,
                chatId: $chatId,
                workspaceId: ($this->workspaceIdOf)($chatId),
            ))->allowed;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Workspace owning a chat via the tg_entities link (D2), non-bot entities
     * only — a bot row is an ownership fact, not a chat. Null when the
     * platform table is unavailable: chat-scoped grants still apply,
     * workspace-scope grants are simply not loaded (deny-by-default).
     */
    private static function workspaceResolver(): \Closure
    {
        return static function (int $chatId): ?int {
            try {
                $workspaceId = DB::table('tg_entities')
                    ->where('kind', '!=', 'bot')
                    ->where('external_id', $chatId)
                    ->orderBy('id')
                    ->value('workspace_id');
            } catch (\Throwable) {
                return null;
            }

            return $workspaceId === null ? null : (int) $workspaceId;
        };
    }
}
