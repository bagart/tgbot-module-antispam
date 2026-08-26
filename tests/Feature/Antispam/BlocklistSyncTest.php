<?php

declare(strict_types=1);

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotManagement\Models\TgModuleEnablement;
use BAGArt\TelegramBotAntispam\Commands\BlocklistSyncCommand;
use BAGArt\TelegramBotAntispam\Models\AntispamBlocklistFeed;
use BAGArt\TelegramBotAntispam\Models\AntispamUserListEntry;
use BAGArt\TelegramBotAntispam\Models\AntispamViolation;

require_once __DIR__.'/AntispamHelpers.php';
require_once __DIR__.'/Admin/AdminHelpers.php';

function blocklistBanViolation(): AntispamViolation
{
    return AntispamViolation::factory()->forScope('source_bot', 100, 42)->create([
        'enforcement_action' => 'ban',
        'status' => AntispamViolation::STATUS_PENDING,
        'matched_rules' => [
            ['ruleId' => 'advertising.regex', 'score' => 60, 'severity' => 'high', 'kind' => 'hard', 'group' => 'advertising', 'reason' => 'pattern'],
        ],
    ]);
}

beforeEach(function () {
    config(['antispam.counter_driver' => 'memory']);

    TgBot::create(['bot_id' => 'source_bot', 'token' => 't:src']);
    TgBot::create(['bot_id' => 'subscriber_bot', 'token' => 't:sub']);
});

it('publishes a feed row when a ban is enforced', function () {
    $violation = blocklistBanViolation();

    app(\BAGArt\TelegramBotAntispam\Enforcement\ActionExecutor::class)
        ->execute($violation, new TgBotConfig(token: 'x:token', botId: 'source_bot'));

    $feed = AntispamBlocklistFeed::query()->sole();
    expect($feed->source_bot_id)->toBe('source_bot')
        ->and($feed->user_id)->toBe(42)
        ->and($feed->reason)->toContain('advertising.regex')
        ->and($feed->published_at)->not->toBeNull();
});

it('is idempotent on repeated ban publishing (one feed row per user)', function () {
    $executor = app(\BAGArt\TelegramBotAntispam\Enforcement\ActionExecutor::class);
    $botConfig = new TgBotConfig(token: 'x:token', botId: 'source_bot');

    $executor->execute(blocklistBanViolation(), $botConfig);
    $executor->execute(blocklistBanViolation(), $botConfig);

    expect(AntispamBlocklistFeed::query()->count())->toBe(1);
});

it('ingests bans into enabled chats of opted-in subscriber bots', function () {
    TgModuleEnablement::factory()->forBot('subscriber_bot')->create([
        'module_id' => 'antispam',
        'module_settings' => ['blocklist_sync' => ['enabled' => true]],
    ]);
    TgModuleEnablement::factory()->forChat('subscriber_bot', 100)->enabled(true)->create(['module_id' => 'antispam']);
    TgModuleEnablement::factory()->forChat('subscriber_bot', 200)->enabled(true)->create(['module_id' => 'antispam']);
    // disabled chat must not receive entries
    TgModuleEnablement::factory()->forChat('subscriber_bot', 300)->enabled(false)->create(['module_id' => 'antispam']);

    AntispamBlocklistFeed::factory()->fromSource('source_bot', 42)->create();

    $this->artisan(BlocklistSyncCommand::class)->assertSuccessful();

    $entries = AntispamUserListEntry::query()->where('user_id', 42)->get();
    expect($entries->pluck('chat_id')->sort()->values()->all())->toBe([100, 200])
        ->and($entries->first()->list_type)->toBe('blacklist')
        ->and($entries->first()->reason)->toBe('blocklist:source_bot')
        ->and($entries->first()->created_by)->toBe('antispam:blocklist')
        ->and($entries->first()->expires_at->getTimestamp())->toBeGreaterThan(now()->getTimestamp());
});

it('dedupes on re-sync instead of duplicating entries', function () {
    TgModuleEnablement::factory()->forBot('subscriber_bot')->create([
        'module_id' => 'antispam',
        'module_settings' => ['blocklist_sync' => ['enabled' => true]],
    ]);
    TgModuleEnablement::factory()->forChat('subscriber_bot', 100)->enabled(true)->create(['module_id' => 'antispam']);

    AntispamBlocklistFeed::factory()->fromSource('source_bot', 42)->create();
    $this->artisan(BlocklistSyncCommand::class)->assertSuccessful();
    $this->artisan(BlocklistSyncCommand::class)->assertSuccessful();

    expect(AntispamUserListEntry::query()->where('user_id', 42)->count())->toBe(1);
});

it('skips bots without the opt-in toggle', function () {
    TgModuleEnablement::factory()->forChat('subscriber_bot', 100)->enabled(true)->create(['module_id' => 'antispam']);

    AntispamBlocklistFeed::factory()->fromSource('source_bot', 42)->create();
    $this->artisan(BlocklistSyncCommand::class)->assertSuccessful();

    expect(AntispamUserListEntry::query()->count())->toBe(0);
});

it('never ingests a bot\'s own bans back into itself', function () {
    TgModuleEnablement::factory()->forBot('source_bot')->create([
        'module_id' => 'antispam',
        'module_settings' => ['blocklist_sync' => ['enabled' => true]],
    ]);
    TgModuleEnablement::factory()->forChat('source_bot', 100)->enabled(true)->create(['module_id' => 'antispam']);

    AntispamBlocklistFeed::factory()->fromSource('source_bot', 42)->create();
    $this->artisan(BlocklistSyncCommand::class, ['--bot' => 'source_bot'])->assertSuccessful();

    expect(AntispamUserListEntry::query()->count())->toBe(0);
});

it('stores the opt-in toggle via the admin endpoint', function () {
    antispamAdminSetup();
    antispamAdminActingAs();

    $response = test()->postJson(route('antispam.user-lists.toggleBlocklistSync'), [
        'bot_id' => 'admin_bot',
        'enabled' => true,
    ]);

    $response->assertRedirect();

    $settings = TgModuleEnablement::query()
        ->where('bot_id', 'admin_bot')
        ->whereNull('chat_id')
        ->where('module_id', 'antispam')
        ->first()
        ?->module_settings;

    expect($settings['blocklist_sync']['enabled'])->toBeTrue();
});
