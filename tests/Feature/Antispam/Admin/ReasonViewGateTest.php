<?php

declare(strict_types=1);

require_once __DIR__.'/AdminHelpers.php';

use App\Models\User;
use BAGArt\TelegramBotAntispam\Auth\T2Gate;
use BAGArt\TelegramBotAntispam\Models\AntispamViolation;
use BAGArt\TelegramBotManagement\Models\TgEntity;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Linked viewer (platform user with a telegram_id) acting for the request.
 */
function reasonGateViewer(int $telegramId): User
{
    $user = User::factory()->create(['telegram_id' => $telegramId]);

    test()->actingAs($user);

    return $user;
}

function reasonGateGrant(int $userId, ?int $chatId = null, ?int $workspaceId = null, string $capability = T2Gate::CAPABILITY): void
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

function reasonGateViolation(): string
{
    return antispamViolationRow([
        'evaluation_snapshot' => [
            'policyVersion' => 'antispam.policy.v1',
            'rulesetVersion' => 'abc123',
            'matchedRules' => [
                ['ruleId' => 'flood.rate.burst', 'score' => 30, 'severity' => 'high', 'kind' => 'soft', 'group' => 'flood', 'reason' => 'rate'],
            ],
        ],
    ]);
}

function reasonGateAppeal(): string
{
    $violationId = reasonGateViolation();
    $appeal = \BAGArt\TelegramBotAntispam\Models\AntispamAppeal::factory()->create([
        'violation_id' => $violationId,
        'user_id' => 42,
        'message' => 'It was not spam',
    ]);

    return (string) $appeal->id;
}

function reasonGateListEntry(): string
{
    $entry = \BAGArt\TelegramBotAntispam\Models\AntispamUserListEntry::query()->create([
        'list_type' => 'blacklist',
        'bot_id' => 'admin_bot',
        'chat_id' => 100,
        'user_id' => 777,
        'reason' => 'known spammer',
    ]);

    return (string) $entry->id;
}

beforeEach(function () {
    antispamAdminSetup();
});

it('wires the T2 gate into the container (interface_exists guard)', function () {
    expect(app()->bound(T2Gate::class))->toBeTrue();
});

it('shows stored reasons to a viewer without a platform telegram link', function () {
    antispamAdminActingAs();
    $violationId = reasonGateViolation();

    $this->get(route('antispam.violations.index', ['status' => 'all']))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
            ->component('antispam/violations')
            ->where('violations.data.0.id', $violationId)
            ->where('violations.data.0.matchedRules.0.reason', 'rate')
            ->where('violations.data.0.evaluationSnapshot.matchedRules.0.reason', 'rate'),
        );
});

it('redacts stored reasons for a linked viewer without a grant', function () {
    reasonGateViewer(555001);
    $violationId = reasonGateViolation();

    $this->get(route('antispam.violations.index', ['status' => 'all']))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
            ->where('violations.data.0.matchedRules.0.reason', '')
            ->where('violations.data.0.matchedRules.0.ruleId', 'flood.rate.burst')
            ->where('violations.data.0.evaluationSnapshot.matchedRules.0.reason', ''),
        );

    $stored = AntispamViolation::query()->findOrFail($violationId);
    expect($stored->matched_rules[0]['reason'])->toBe('rate');
});

it('shows stored reasons to a linked viewer holding a chat-scoped grant', function () {
    $user = reasonGateViewer(555002);
    reasonGateGrant($user->id, chatId: 100);
    reasonGateViolation();

    $this->get(route('antispam.violations.index', ['status' => 'all']))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
            ->where('violations.data.0.matchedRules.0.reason', 'rate')
            ->where('violations.data.0.evaluationSnapshot.matchedRules.0.reason', 'rate'),
        );
});

it('shows stored reasons to a linked viewer holding a workspace grant for the chat workspace', function () {
    $entity = TgEntity::factory()->chatGroup()->create(['external_id' => 100]);
    $user = reasonGateViewer(555003);
    reasonGateGrant($user->id, workspaceId: (int) $entity->workspace_id);
    reasonGateViolation();

    $this->get(route('antispam.violations.index', ['status' => 'all']))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
            ->where('violations.data.0.matchedRules.0.reason', 'rate'),
        );
});

it('keeps reasons redacted when the grant belongs to another workspace', function () {
    $entity = TgEntity::factory()->chatGroup()->create(['external_id' => 100]);
    $user = reasonGateViewer(555004);
    // Workspace 999999 is not the workspace owning chat 100
    reasonGateGrant($user->id, workspaceId: 999999);
    reasonGateViolation();

    $this->get(route('antispam.violations.index', ['status' => 'all']))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
            ->where('violations.data.0.matchedRules.0.reason', ''),
        );

    expect((int) $entity->workspace_id)->not->toBe(999999);
});

it('ignores grants carrying a different capability', function () {
    $user = reasonGateViewer(555005);
    reasonGateGrant($user->id, chatId: 100, capability: 'menu.invoke');
    reasonGateViolation();

    $this->get(route('antispam.violations.index', ['status' => 'all']))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
            ->where('violations.data.0.matchedRules.0.reason', ''),
        );
});

it('redacts appeal violation rules for a linked viewer without a grant', function () {
    reasonGateViewer(555006);
    $appealId = reasonGateAppeal();

    $this->get(route('antispam.appeals.index'))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
            ->component('antispam/appeals')
            ->where('appeals.data.0.id', (string) $appealId)
            ->where('appeals.data.0.violation.matchedRules.0.reason', '')
            ->where('appeals.data.0.violation.matchedRules.0.ruleId', 'flood.rate.burst'),
        );
});

it('shows appeal violation rules to a linked viewer holding a chat-scoped grant', function () {
    $user = reasonGateViewer(555007);
    reasonGateGrant($user->id, chatId: 100);
    reasonGateAppeal();

    $this->get(route('antispam.appeals.index'))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
            ->where('appeals.data.0.violation.matchedRules.0.reason', 'rate'),
        );
});

it('nulls user-list reasons for a linked viewer without a grant and keeps the stored row', function () {
    reasonGateViewer(555008);
    $entryId = reasonGateListEntry();

    $this->get(route('antispam.user-lists.index'))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
            ->component('antispam/user-lists')
            ->where('entries.data.0.id', $entryId)
            ->whereNull('entries.data.0.reason'),
        );

    expect(
        DB::table('antispam_user_list_entries')->where('id', $entryId)->value('reason'),
    )->toBe('known spammer');
});

it('shows user-list reasons to a viewer without a platform telegram link', function () {
    antispamAdminActingAs();
    $entryId = reasonGateListEntry();

    $this->get(route('antispam.user-lists.index'))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
            ->where('entries.data.0.id', $entryId)
            ->where('entries.data.0.reason', 'known spammer'),
        );
});

it('shows user-list reasons to a linked viewer holding a chat-scoped grant', function () {
    $user = reasonGateViewer(555009);
    reasonGateGrant($user->id, chatId: 100);
    reasonGateListEntry();

    $this->get(route('antispam.user-lists.index'))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page) => $page
            ->where('entries.data.0.reason', 'known spammer'),
        );
});

it('keeps writing reasons through the user-list store (the gate is view-only)', function () {
    reasonGateViewer(555010);

    $this->post(route('antispam.user-lists.store'), [
        'list_type' => 'blacklist',
        'bot_id' => 'admin_bot',
        'chat_id' => 100,
        'user_id' => 4242,
        'reason' => 'flooded the group',
    ])->assertRedirect(route('antispam.user-lists.index'));

    expect(
        DB::table('antispam_user_list_entries')
            ->where('bot_id', 'admin_bot')
            ->where('chat_id', 100)
            ->where('user_id', 4242)
            ->value('reason'),
    )->toBe('flooded the group');
});
