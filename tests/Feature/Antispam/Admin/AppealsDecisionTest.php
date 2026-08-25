<?php

declare(strict_types=1);

require_once __DIR__.'/AdminHelpers.php';

use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\TgApi\Methods\DTO\RestrictChatMemberMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\DTO\UnbanChatMemberMethodDTO;
use BAGArt\TelegramBotAntispam\Models\AntispamAppeal;
use BAGArt\TelegramBotAntispam\Models\AntispamViolation;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Inserts a violation row and a pending appeal against it.
 *
 * @return array{0: string, 1: AntispamAppeal}
 */
function antispamPendingAppeal(array $violationOverrides = []): array
{
    $violationId = antispamViolationRow($violationOverrides);
    $appeal = AntispamAppeal::factory()->create([
        'violation_id' => $violationId,
        'user_id' => 42,
        'message' => 'It was not spam',
    ]);

    return [$violationId, $appeal];
}

beforeEach(function () {
    antispamAdminSetup();
});

it('lists appeals with violation context', function () {
    antispamAdminActingAs();
    [, $appeal] = antispamPendingAppeal(['enforcement_action' => 'ban']);

    $this->get(route('antispam.appeals.index'))->assertOk()->assertInertia(
        fn (Assert $page) => $page
            ->component('antispam/appeals')
            ->has('appeals.data', 1)
            ->where('appeals.data.0.status', 'pending')
            ->where('appeals.data.0.violation.id', (string) $appeal->violation_id)
            ->where('appeals.data.0.violation.enforcementAction', 'ban'),
    );
});

it('approving an appeal lifts a ban and overturns the violation', function () {
    antispamAdminActingAs();
    [$violationId, $appeal] = antispamPendingAppeal(['enforcement_action' => 'ban']);
    $spy = antispamSenderSpy();
    app()->instance(TgSenderContract::class, $spy);

    $response = $this->postJson(
        route('antispam.appeals.decide', ['appeal' => (string) $appeal->id]),
        ['decision' => 'approve'],
    );

    $response->assertOk()->assertJsonPath('status', 'approved');

    expect($spy->sent)->toContain(UnbanChatMemberMethodDTO::class)
        ->and(DB::table('antispam_violations')->find($violationId)->status)->toBe(AntispamViolation::STATUS_OVERTURNED)
        ->and($appeal->fresh()->decided_by)->toBeString()
        ->and($appeal->fresh()->decided_at)->not->toBeNull();
});

it('approving an appeal restores full send permissions for restrictions', function () {
    antispamAdminActingAs();
    [, $appeal] = antispamPendingAppeal(['enforcement_action' => 'restrict']);
    $spy = antispamSenderSpy();
    app()->instance(TgSenderContract::class, $spy);

    $this->postJson(
        route('antispam.appeals.decide', ['appeal' => (string) $appeal->id]),
        ['decision' => 'approve'],
    )->assertOk();

    $restrict = collect($spy->dtos)->first(fn ($dto) => $dto instanceof RestrictChatMemberMethodDTO);

    expect($spy->sent)->toContain(RestrictChatMemberMethodDTO::class)
        ->and($restrict)->not->toBeNull()
        ->and($restrict->permissions->canSendMessages)->toBeTrue()
        ->and($restrict->untilDate)->toBeNull();
});

it('rejecting an appeal keeps the violation intact and sends nothing', function () {
    antispamAdminActingAs();
    [$violationId, $appeal] = antispamPendingAppeal(['enforcement_action' => 'ban']);
    $spy = antispamSenderSpy();
    app()->instance(TgSenderContract::class, $spy);

    $this->postJson(
        route('antispam.appeals.decide', ['appeal' => (string) $appeal->id]),
        ['decision' => 'reject'],
    )->assertOk()->assertJsonPath('status', 'rejected');

    expect($spy->sent)->toBeEmpty()
        ->and(DB::table('antispam_violations')->find($violationId)->status)->toBe(AntispamViolation::STATUS_APPLIED)
        ->and($appeal->fresh()->status)->toBe(AntispamAppeal::STATUS_REJECTED);
});

it('is idempotent: a decided appeal cannot be decided again', function () {
    antispamAdminActingAs();
    [, $appeal] = antispamPendingAppeal(['enforcement_action' => 'ban']);
    $spy = antispamSenderSpy();
    app()->instance(TgSenderContract::class, $spy);

    $this->postJson(
        route('antispam.appeals.decide', ['appeal' => (string) $appeal->id]),
        ['decision' => 'approve'],
    )->assertOk();

    $this->postJson(
        route('antispam.appeals.decide', ['appeal' => (string) $appeal->id]),
        ['decision' => 'approve'],
    )->assertStatus(409);

    expect(count($spy->sent))->toBe(1);
});

it('rejects decisions with invalid payloads', function () {
    antispamAdminActingAs();
    [, $appeal] = antispamPendingAppeal();

    $this->postJson(
        route('antispam.appeals.decide', ['appeal' => (string) $appeal->id]),
        ['decision' => 'maybe'],
    )->assertUnprocessable();

    $this->postJson(
        route('antispam.appeals.decide', ['appeal' => '00000000-0000-0000-0000-000000000000']),
        ['decision' => 'approve'],
    )->assertNotFound();
});
