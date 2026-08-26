<?php

declare(strict_types=1);

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBotAntispam\Counters\Counter;
use BAGArt\TelegramBotAntispam\Models\AntispamViolation;
use BAGArt\TelegramBotManagement\Models\TgModuleEnablement;

require_once __DIR__.'/AntispamHelpers.php';

/**
 * Final-phase validation: graceful degradation. Counter storage loss keeps
 * content rules working (fail-open); enablement storage loss disables the
 * module instead of blocking chats.
 */
beforeEach(function () {
    config(['antispam.counter_driver' => 'memory']);

    \BAGArt\TelegramBotManagement\Models\TgBot::create(['bot_id' => 'test_bot', 'token' => 't:token']);
});

it('keeps content rules firing when the counter layer degrades', function () {
    TgModuleEnablement::factory()->forChat('test_bot', 100)->enabled(true)->create(['module_id' => 'antispam']);

    app()->instance(Counter::class, new class () implements Counter
    {
        public function record(\BAGArt\TelegramBotAntispam\Counters\CounterBatch $batch): \BAGArt\TelegramBotAntispam\Domain\CounterSnapshot
        {
            throw new RuntimeException('redis is down');
        }
    });

    $spy = senderSpy();
    $pipeline = pipelineWith($spy);
    $botConfig = new TgBotConfig(token: 'x:token', botId: 'test_bot');

    // Hard advertising detection needs no counters — must still fire.
    $outcome = $pipeline->handle(antispamMessage(100, 42, 'join t.me/spam_channel now'), $botConfig);

    expect($outcome)->not->toBeNull()
        ->and($outcome->allows())->toBeFalse()
        ->and(AntispamViolation::query()->count())->toBe(1)
        ->and($spy->sent)->toContain(\BAGArt\TelegramBot\TgApi\Methods\DTO\RestrictChatMemberMethodDTO::class);
});

it('degrades rate rules to no-detection when the counter layer degrades', function () {
    TgModuleEnablement::factory()->forChat('test_bot', 100)->enabled(true)->create(['module_id' => 'antispam']);

    app()->instance(Counter::class, new class () implements Counter
    {
        public function record(\BAGArt\TelegramBotAntispam\Counters\CounterBatch $batch): \BAGArt\TelegramBotAntispam\Domain\CounterSnapshot
        {
            throw new RuntimeException('redis is down');
        }
    });

    $pipeline = pipelineWith(senderSpy());
    $botConfig = new TgBotConfig(token: 'x:token', botId: 'test_bot');

    // A benign message stays clean; nothing crashes, nothing persists.
    $outcome = $pipeline->handle(
        antispamMessage(100, 42, 'just a normal message in the group'),
        $botConfig,
    );

    expect($outcome?->allows())->toBeTrue()
        ->and(AntispamViolation::query()->count())->toBe(0);
});

it('returns a clean evaluation when the module is disabled for the chat', function () {
    TgModuleEnablement::factory()->forChat('test_bot', 100)->enabled(false)->create(['module_id' => 'antispam']);

    $pipeline = pipelineWith(senderSpy());
    $botConfig = new TgBotConfig(token: 'x:token', botId: 'test_bot');

    // Even hard spam passes untouched: disabled = fail-open by design
    // (failClosed descriptor semantics), enforcement never blocks chats.
    $outcome = $pipeline->handle(antispamMessage(100, 42, 'join t.me/spam_channel now'), $botConfig);

    expect($outcome)->toBeNull()
        ->and(AntispamViolation::query()->count())->toBe(0);
});
