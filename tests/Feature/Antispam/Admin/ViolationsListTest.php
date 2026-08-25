<?php

declare(strict_types=1);

require_once __DIR__.'/AdminHelpers.php';

use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    antispamAdminSetup();
    antispamAdminActingAs();
});

it('lists violations', function () {
    antispamViolationRow();

    $this->get(route('antispam.violations.index', ['status' => 'all']))
        ->assertOk()
        ->assertInertia(
            fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('antispam/violations')
                ->has('violations.data', 1, fn (AssertableJson $json) => $json
                    ->where('botId', 'admin_bot')
                    ->where('userId', 42)
                    ->where('score', 90)
                    ->where('status', 'applied')
                    ->etc()),
        );
});

it('filters by status', function () {
    antispamViolationRow(['status' => 'pending']);
    antispamViolationRow(['status' => 'overturned']);

    $this->get(route('antispam.violations.index', ['status' => 'pending']))
        ->assertOk()
        ->assertInertia(
            fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->has('violations.data', 1)
                ->where('violations.data.0.status', 'pending'),
        );
});

it('filters by group through the matched rules json', function () {
    antispamViolationRow(['user_id' => 7]);
    antispamViolationRow([
        'user_id' => 8,
        'matched_rules' => [
            ['ruleId' => 'flood.rate.burst', 'score' => 30, 'severity' => 'high', 'kind' => 'soft', 'group' => 'flood', 'reason' => 'rate'],
        ],
    ]);

    $this->get(route('antispam.violations.index', ['status' => 'all', 'group' => 'advertising']))
        ->assertOk()
        ->assertInertia(
            fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->has('violations.data', 1)
                ->where('violations.data.0.userId', 7),
        );
});

it('filters by chat, user and date range', function () {
    antispamViolationRow(['chat_id' => 100, 'user_id' => 11]);
    antispamViolationRow(['chat_id' => 200, 'user_id' => 12]);

    $this->get(route('antispam.violations.index', ['status' => 'all', 'chat_id' => 200]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('violations.data', 1)->where('violations.data.0.chatId', 200));

    $this->get(route('antispam.violations.index', ['status' => 'all', 'user_id' => 11]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('violations.data', 1)->where('violations.data.0.userId', 11));

    $tomorrow = now()->addDay()->toDateString();
    $this->get(route('antispam.violations.index', ['status' => 'all', 'date_from' => $tomorrow]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('violations.data', 0));
});
