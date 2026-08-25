<?php

declare(strict_types=1);

require_once __DIR__.'/AdminHelpers.php';

use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\TgApi\Methods\DTO\BanChatMemberMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\DTO\DeleteMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\DTO\RestrictChatMemberMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\DTO\UnbanChatMemberMethodDTO;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    antispamAdminSetup();
    antispamAdminActingAs();
});

function bindModerationSpy(): TgSenderContract
{
    $spy = antispamSenderSpy();
    app()->instance(TgSenderContract::class, $spy);

    return $spy;
}

it('shows pending violations by default as the moderation queue', function () {
    $pendingId = antispamViolationRow(['status' => 'pending', 'message_id' => 1]);
    antispamViolationRow(['status' => 'applied', 'message_id' => 2]);

    $this->get(route('antispam.violations.index'))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
                ->has('violations.data', 1)
                ->where('violations.data.0.id', $pendingId)
                ->where('filters.status', 'pending'),
        );
});

it('lists every status when explicitly requested', function () {
    antispamViolationRow(['status' => 'pending', 'message_id' => 1]);
    antispamViolationRow(['status' => 'applied', 'message_id' => 2]);

    $this->get(route('antispam.violations.index', ['status' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('violations.data', 2));
});

it('applies a pending violation and executes enforcement', function () {
    $violationId = antispamViolationRow(['status' => 'pending']);
    $spy = bindModerationSpy();

    $this->postJson(route('antispam.violations.action', ['violationId' => $violationId]), ['action' => 'apply'])
        ->assertOk()
        ->assertJsonPath('status', 'applied');

    expect($spy->sent)->toBe([DeleteMessageMethodDTO::class, RestrictChatMemberMethodDTO::class])
        ->and(DB::table('antispam_violations')->find($violationId)->status)->toBe('applied');
});

it('overturns an applied violation and lifts the ban', function () {
    $violationId = antispamViolationRow(['status' => 'applied', 'enforcement_action' => 'ban']);
    $spy = bindModerationSpy();

    $this->postJson(route('antispam.violations.action', ['violationId' => $violationId]), ['action' => 'overturn'])
        ->assertOk()
        ->assertJsonPath('status', 'overturned');

    expect($spy->sent)->toContain(UnbanChatMemberMethodDTO::class)
        ->and(DB::table('antispam_violations')->find($violationId)->status)->toBe('overturned');
});

it('escalates an applied restriction to a ban', function () {
    $violationId = antispamViolationRow(['status' => 'applied', 'enforcement_action' => 'restrict']);
    $spy = bindModerationSpy();

    $this->postJson(route('antispam.violations.action', ['violationId' => $violationId]), ['action' => 'escalate'])
        ->assertOk()
        ->assertJsonPath('status', 'escalated')
        ->assertJsonPath('enforcementAction', 'ban');

    expect($spy->sent)->toContain(BanChatMemberMethodDTO::class);
});

it('rejects repeated actions on the same violation', function () {
    $violationId = antispamViolationRow(['status' => 'pending']);
    $spy = bindModerationSpy();

    $this->postJson(route('antispam.violations.action', ['violationId' => $violationId]), ['action' => 'apply'])
        ->assertOk();

    $this->postJson(route('antispam.violations.action', ['violationId' => $violationId]), ['action' => 'apply'])
        ->assertStatus(409);

    expect(count($spy->sent))->toBe(2);
});

it('validates action payloads', function () {
    $violationId = antispamViolationRow();

    $this->postJson(route('antispam.violations.action', ['violationId' => $violationId]), ['action' => 'nuke'])
        ->assertUnprocessable();

    $this->postJson(route('antispam.violations.action', ['violationId' => '00000000-0000-0000-0000-000000000000']), ['action' => 'apply'])
        ->assertNotFound();
});

it('bulk-applies actions and reports skips', function () {
    $pending = antispamViolationRow(['status' => 'pending']);
    $applied = antispamViolationRow(['status' => 'applied', 'message_id' => 2]);
    bindModerationSpy();

    $response = $this->postJson(route('antispam.violations.bulkAction'), [
        'action' => 'apply',
        'ids' => [$pending, $applied, '00000000-0000-0000-0000-000000000099'],
    ])->assertOk();

    expect($response->json('updated'))->toBe([['id' => $pending, 'status' => 'applied']])
        ->and(array_column($response->json('skipped'), 'id'))->toBe([
            $applied,
            '00000000-0000-0000-0000-000000000099',
        ]);
});

it('skips bulk ids whose bot has no token on record', function () {
    $orphan = antispamViolationRow(['bot_id' => 'ghost_bot', 'status' => 'pending']);

    $this->postJson(route('antispam.violations.bulkAction'), [
        'action' => 'apply',
        'ids' => [$orphan],
    ])->assertOk()->assertJsonPath('skipped.0.id', $orphan);
});

function antispamStrikeRow(array $overrides = []): string
{
    $row = [
        'id' => (string) Illuminate\Support\Str::uuid(),
        'violation_id' => (string) Illuminate\Support\Str::uuid(),
        'bot_id' => 'admin_bot',
        'chat_id' => 100,
        'user_id' => 42,
        'strike_consequence' => 'mute_6h',
        'expired_at' => now()->addDay(),
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];

    DB::table('antispam_strike_events')->insert($row);

    return (string) $row['id'];
}

it('returns a chronological combined history for a bot user pair', function () {
    $oldViolation = antispamViolationRow(['created_at' => now()->subDays(2)]);
    antispamStrikeRow(['created_at' => now()->subDay()]);
    $newViolation = antispamViolationRow(['user_id' => 42, 'message_id' => 9, 'created_at' => now()]);
    antispamViolationRow(['user_id' => 777, 'message_id' => 10, 'created_at' => now()]);

    $events = $this->getJson(route('antispam.violations.history', ['bot_id' => 'admin_bot', 'user_id' => 42]))
        ->assertOk()
        ->assertJsonPath('botId', 'admin_bot')
        ->assertJsonPath('userId', 42)
        ->json('events');

    expect(count($events))->toBe(3)
        ->and(array_column($events, 'type'))->toBe(['violation', 'strike', 'violation'])
        ->and($events[0]['id'])->toBe($oldViolation)
        ->and($events[1]['consequence'])->toBe('mute_6h')
        ->and($events[2]['id'])->toBe($newViolation);
});
