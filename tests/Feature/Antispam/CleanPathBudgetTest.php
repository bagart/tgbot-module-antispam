<?php

declare(strict_types=1);

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBotManagement\Models\TgModuleEnablement;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/AntispamHelpers.php';

/**
 * Performance budgets (todo.antispam.md final phase): a clean group message
 * must not touch the database at all. Caches are warmed by a first message;
 * the second (clean) one must run purely on cache + counters.
 */
beforeEach(function () {
    config(['antispam.counter_driver' => 'memory']);

    \BAGArt\TelegramBotManagement\Models\TgBot::create(['bot_id' => 'test_bot', 'token' => 't:token']);
    TgModuleEnablement::factory()->forChat('test_bot', 100)->enabled(true)->create(['module_id' => 'antispam']);
});

it('runs zero DB queries for a clean message once caches are warm', function () {
    $botConfig = new TgBotConfig(token: 'x:token', botId: 'test_bot');
    $pipeline = pipelineWith(senderSpy());

    // Warm-up pass: compiles the plan, fills enablement/settings/list caches.
    $pipeline->handle(antispamMessage(100, 42, 'warm-up message'), $botConfig);

    $queries = [];
    DB::listen(function (Illuminate\Database\Events\QueryExecuted $event) use (&$queries): void {
        $queries[] = $event->sql;
    });

    $outcome = $pipeline->handle(antispamMessage(100, 42, 'just chatting here'), $botConfig);

    expect($outcome?->allows())->toBeTrue()
        ->and($queries)->toBe([]);
});
