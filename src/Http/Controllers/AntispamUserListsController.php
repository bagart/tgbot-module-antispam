<?php

namespace BAGArt\TelegramBotAntispam\Http\Controllers;

use BAGArt\TelegramBotAntispam\Models\AntispamUserListEntry;
use BAGArt\TelegramBotAntispam\UserList\UserListManager;
use BAGArt\TelegramBotManagement\Models\TgBot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AntispamUserListsController
{
    public function __construct(
        private readonly UserListManager $lists,
    ) {
    }

    public function index(): Response
    {
        return Inertia::render('antispam/user-lists', [
            'entries' => AntispamUserListEntry::query()
                ->orderBy('bot_id')
                ->orderBy('chat_id')
                ->paginate(50)
                ->withQueryString(),
            'bots' => TgBot::query()->orderBy('bot_id')->get(['bot_id']),
        ]);
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
