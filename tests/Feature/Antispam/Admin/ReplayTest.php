<?php

declare(strict_types=1);

require_once __DIR__.'/AdminHelpers.php';

beforeEach(function () {
    antispamAdminSetup();
    antispamAdminActingAs();
});

it('compares the stored verdict against the current policy', function () {
    // Stored verdict: restrict at 90 points. Default plan restricts at 80 —
    // replay of the same detections must stay 'restrict'.
    $id = antispamViolationRow();

    $response = $this->postJson(route('antispam.violations.replay', ['violationId' => $id]));

    $response->assertOk()->assertJsonPath('violationId', $id)
        ->assertJsonPath('oldAction', 'restrict')
        ->assertJsonPath('newAction', 'restrict')
        ->assertJsonPath('changed', false);
});

it('shows a verdict change when the policy becomes stricter', function () {
    // Stored: warn at 30. After switching to strict thresholds (restrict=48),
    // a 90-point violation replays to ban.
    $id = antispamViolationRow([
        'score' => 30,
        'verdict' => ['action' => 'warn', 'policyVersion' => 'antispam.policy.v1'],
        'enforcement_action' => 'delete',
        'status' => 'pending',
    ]);

    $this->put(route('antispam.chats.updateSettings', ['botId' => 'admin_bot', 'chatId' => 100]), [
        'thresholds' => ['warn' => 10, 'restrict' => 20, 'ban' => 25],
    ])->assertRedirect();

    $response = $this->postJson(route('antispam.violations.replay', ['violationId' => $id]));

    $response->assertOk()
        ->assertJsonPath('oldAction', 'warn')
        ->assertJsonPath('newAction', 'ban')
        ->assertJsonPath('changed', true);
});

it('404s for unknown violations', function () {
    $this->postJson(route('antispam.violations.replay', ['violationId' => '00000000-0000-0000-0000-000000000000']))
        ->assertNotFound();
});
