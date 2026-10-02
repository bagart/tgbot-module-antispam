<?php

declare(strict_types=1);

require_once __DIR__.'/AdminHelpers.php';

use App\Models\User;
use BAGArt\TelegramBotAntispam\Auth\T2Gate;
use BAGArt\TelegramBotManagement\Models\TgEntity;
use Illuminate\Support\Facades\DB;

/** Linked viewer (platform user with a telegram_id) acting for the request. */
function logGateViewer(int $telegramId): User
{
    $user = User::factory()->create(['telegram_id' => $telegramId]);

    test()->actingAs($user);

    return $user;
}

function logGateGrant(int $userId, ?int $chatId = null, ?int $workspaceId = null, string $capability = T2Gate::LOG_CAPABILITY): void
{
    DB::table('access_grants')->insert([
        'bot_id' => 'admin_bot',
        'subject_id' => (string) $userId,
        'scope' => $workspaceId !== null ? 'workspace' : ($chatId !== null ? 'chat' : 'bot'),
        'capability' => $capability,
        'effect' => 'allow',
        'chat_id' => $chatId,
        'workspace_id' => $workspaceId,
    ]);
}

/** antispamViolationRow with timestamps — the history endpoint renders created_at. */
function logGateViolationRow(array $overrides = []): string
{
    return antispamViolationRow(['created_at' => now()->subDay(), 'updated_at' => now(), ...$overrides]);
}

function logGateStrikeRow(int $chatId = 100): string
{
    $id = (string) Illuminate\Support\Str::uuid();
    DB::table('antispam_strike_events')->insert([
        'id' => $id,
        'violation_id' => (string) Illuminate\Support\Str::uuid(),
        'bot_id' => 'admin_bot',
        'chat_id' => $chatId,
        'user_id' => 42,
        'strike_consequence' => 'mute_6h',
        'expired_at' => now()->addDay(),
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/**
 * @return list<array<string, mixed>>
 */
function logGateEvents(User $viewer, int $userId = 42): array
{
    test()->actingAs($viewer);

    return test()
        ->getJson(route('antispam.violations.history', ['bot_id' => 'admin_bot', 'user_id' => $userId]))
        ->assertOk()
        ->json('events');
}

beforeEach(function () {
    antispamAdminSetup();
});

it('returns the full history to a viewer without a platform telegram link', function () {
    $viewer = User::factory()->create();
    test()->actingAs($viewer);
    logGateViolationRow(['created_at' => now()->subDay()]);
    logGateStrikeRow();

    $events = logGateEvents($viewer);

    expect($events)->toHaveCount(2)
        ->and(array_column($events, 'type'))->toBe(['violation', 'strike']);
});

it('drops every history event for a linked viewer without a grant', function () {
    $viewer = logGateViewer(666001);
    logGateViolationRow();
    logGateStrikeRow();

    expect(logGateEvents($viewer))->toBe([]);
});

it('returns history events to a linked viewer holding the chat-scoped grant', function () {
    $viewer = logGateViewer(666002);
    logGateGrant($viewer->id, chatId: 100);
    logGateViolationRow();
    logGateStrikeRow();

    expect(logGateEvents($viewer))->toHaveCount(2);
});

it('returns history events to a linked viewer holding a workspace grant for the chat workspace', function () {
    $entity = TgEntity::factory()->chatGroup()->create(['external_id' => 100]);
    $viewer = logGateViewer(666003);
    logGateGrant($viewer->id, workspaceId: (int) $entity->workspace_id);
    logGateViolationRow();

    expect(logGateEvents($viewer))->toHaveCount(1);
});

it('keeps history empty when the grant belongs to another workspace', function () {
    $entity = TgEntity::factory()->chatGroup()->create(['external_id' => 100]);
    $viewer = logGateViewer(666004);
    logGateGrant($viewer->id, workspaceId: 999999);
    logGateViolationRow();

    expect(logGateEvents($viewer))->toBe([])
        ->and((int) $entity->workspace_id)->not->toBe(999999);
});

it('keeps history events per chat: only granted chats are returned', function () {
    $viewer = logGateViewer(666005);
    logGateGrant($viewer->id, chatId: 100);
    logGateViolationRow(['chat_id' => 100]);
    logGateStrikeRow(chatId: 200);

    $events = logGateEvents($viewer);

    expect($events)->toHaveCount(1)
        ->and($events[0]['chatId'])->toBe(100);
});

it('does not let a moderation.reason.view grant unlock the moderation log', function () {
    $viewer = logGateViewer(666006);
    logGateGrant($viewer->id, chatId: 100, capability: T2Gate::CAPABILITY);
    logGateViolationRow();

    expect(logGateEvents($viewer))->toBe([]);
});

it('wires the log capability beside the reason capability on the gate', function () {
    expect(app()->bound(T2Gate::class))->toBeTrue()
        ->and(T2Gate::LOG_CAPABILITY)->toBe('moderation.log.view');
});
