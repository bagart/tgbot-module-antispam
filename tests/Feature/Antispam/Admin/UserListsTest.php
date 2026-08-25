<?php

declare(strict_types=1);

require_once __DIR__.'/AdminHelpers.php';

use BAGArt\TelegramBotAntispam\Models\AntispamUserListEntry;

beforeEach(function () {
    antispamAdminSetup();
    antispamAdminActingAs();
});

it('creates a list entry and refreshes the cache', function () {
    $this->post(route('antispam.user-lists.store'), [
        'list_type' => 'whitelist',
        'bot_id' => 'admin_bot',
        'chat_id' => 100,
        'user_id' => 777,
        'reason' => 'trusted admin',
    ])->assertRedirect();

    $entry = AntispamUserListEntry::query()->sole();

    expect($entry->list_type)->toBe('whitelist')
        ->and((int) $entry->user_id)->toBe(777)
        ->and($entry->created_by)->not->toBeNull();

    // The gating cache must reflect the change immediately
    $lists = app(\BAGArt\TelegramBotAntispam\UserList\UserListManager::class);
    expect($lists->isWhitelisted('admin_bot', 100, 777))->toBeTrue()
        ->and($lists->isBlacklisted('admin_bot', 100, 42))->toBeFalse();
});

it('deletes a list entry and refreshes the cache', function () {
    AntispamUserListEntry::query()->create([
        'list_type' => 'blacklist',
        'bot_id' => 'admin_bot',
        'chat_id' => 100,
        'user_id' => 13,
    ]);

    $entry = AntispamUserListEntry::query()->sole();

    $this->delete(route('antispam.user-lists.destroy', $entry))->assertRedirect();

    expect(AntispamUserListEntry::query()->count())->toBe(0);

    $lists = app(\BAGArt\TelegramBotAntispam\UserList\UserListManager::class);
    expect($lists->isBlacklisted('admin_bot', 100, 13))->toBeFalse();
});

it('rejects unknown list types and bots', function () {
    $this->post(route('antispam.user-lists.store'), [
        'list_type' => 'graylist',
        'bot_id' => 'admin_bot',
        'chat_id' => 100,
        'user_id' => 1,
    ])->assertSessionHasErrors('list_type');

    $this->post(route('antispam.user-lists.store'), [
        'list_type' => 'whitelist',
        'bot_id' => 'ghost_bot',
        'chat_id' => 100,
        'user_id' => 1,
    ])->assertSessionHasErrors('bot_id');
});
