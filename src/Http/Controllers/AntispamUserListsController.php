<?php

namespace BAGArt\TelegramBotAntispam\Http\Controllers;

use BAGArt\TelegramBotAntispam\Auth\T2Gate;
use BAGArt\TelegramBotAntispam\Models\AntispamUserListEntry;
use BAGArt\TelegramBotAntispam\UserList\UserListManager;
use BAGArt\TelegramBot\Contracts\Modules\ModuleSettingsContract;
use BAGArt\TelegramBotManagement\Models\TgBot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AntispamUserListsController
{
    use ReasonVisibility;

    public function __construct(
        private readonly UserListManager $lists,
        private readonly ModuleSettingsContract $settings,
        private readonly ?T2Gate $gate = null,
    ) {
    }

    public function index(Request $request): Response
    {
        return Inertia::render('antispam/user-lists', [
            'entries' => AntispamUserListEntry::query()
                ->orderBy('bot_id')
                ->orderBy('chat_id')
                ->paginate(50)
                ->withQueryString()
                ->through(fn (AntispamUserListEntry $entry): AntispamUserListEntry => $this->visibleListEntry(
                    $this->gate,
                    $request,
                    $entry,
                )),
            'bots' => TgBot::query()->orderBy('bot_id')->get(['bot_id']),
            'blocklistSyncBots' => $this->blocklistSyncBotIds(),
        ]);
    }

    /**
     * Federated blocklist opt-in toggle (P3.7): stores
     * {"blocklist_sync": {"enabled": bool}} into the BOT-scope antispam
     * settings and force-enables the bot-scope module row (reserved
     * `enabled` key handled by the contract).
     */
    public function toggleBlocklistSync(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'bot_id' => ['required', 'string', 'max:20', Rule::exists('tg_bots', 'bot_id')],
            'enabled' => ['required', 'boolean'],
        ]);

        $this->settings->patchSettings('antispam', (string) $validated['bot_id'], null, [
            'blocklist_sync' => ['enabled' => $validated['enabled']],
            'enabled' => true,
        ]);

        return to_route('antispam.user-lists.index');
    }

    /** @return list<string> bots with blocklist sync enabled */
    private function blocklistSyncBotIds(): array
    {
        return TgBot::query()
            ->orderBy('bot_id')
            ->pluck('bot_id')
            ->filter(function ($botId): bool {
                $sync = $this->settings->settingsFor('antispam', (string) $botId)['blocklist_sync'] ?? null;

                return is_array($sync) && (bool) ($sync['enabled'] ?? false) === true;
            })
            ->values()
            ->all();
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'list_type' => ['required', Rule::in(['whitelist', 'blacklist'])],
            'bot_id' => ['required', 'string', 'max:20', Rule::exists('tg_bots', 'bot_id')],
            'chat_id' => ['required', 'integer'],
            'user_id' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:500'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        AntispamUserListEntry::query()->updateOrCreate(
            [
                'bot_id' => $validated['bot_id'],
                'chat_id' => $validated['chat_id'],
                'user_id' => $validated['user_id'],
                'list_type' => $validated['list_type'],
            ],
            [
                'reason' => $validated['reason'] ?? null,
                'expires_at' => $validated['expires_at'] ?? null,
                'created_by' => $request->user()?->email,
            ],
        );

        $this->lists->refresh($validated['bot_id'], (int) $validated['chat_id']);

        return to_route('antispam.user-lists.index');
    }

    public function destroy(AntispamUserListEntry $entry): RedirectResponse
    {
        $entry->delete();

        $this->lists->refresh((string) $entry->bot_id, (int) $entry->chat_id);

        return to_route('antispam.user-lists.index');
    }
}
