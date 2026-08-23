<?php

declare(strict_types=1);

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBotManagement\Models\TgModuleEnablement;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/AntispamHelpers.php';

beforeEach(function () {
    config(['antispam.counter_driver' => 'memory']);

    \BAGArt\TelegramBotManagement\Models\TgBot::create(['bot_id' => 'test_bot', 'token' => 't:token']);
    TgModuleEnablement::factory()->forChat('test_bot', 100)->enabled(true)->create();
});

it('runs the full cycle: violation в†’ strike в†’ async enforcement', function () {
    $spy = senderSpy();
    $pipeline = pipelineWith($spy);
    $botConfig = new TgBotConfig(token: 'x:token', botId: 'test_bot');

    // hard advertising detection (+60, severity high) в†’ minimum restrict
    $outcome = $pipeline->handle(antispamMessage(100, 42, 'join t.me/spam_channel now'), $botConfig);

    expect($outcome)->not->toBeNull()
        ->and($outcome->allows())->toBeFalse();

    $violation = \BAGArt\TelegramBotAntispam\Models\AntispamViolation::query()->sole();
    expect($violation->status)->toBe(\BAGArt\TelegramBotAntispam\Models\AntispamViolation::STATUS_APPLIED)
        ->and($violation->enforcement_action)->toBe('restrict')
        ->and($violation->score)->toBeGreaterThanOrEqual(60);

    $strike = \BAGArt\TelegramBotAntispam\Models\AntispamStrikeEvent::query()->sole();
    expect($strike->violation_id)->toBe($violation->id)
        ->and($strike->strike_consequence)->toBe('mute_1h');

    // enforcement went through the outbound-bound sender: delete + restrict
    expect($spy->sent)->toContain(\BAGArt\TelegramBot\TgApi\Methods\DTO\DeleteMessageMethodDTO::class)
        ->and($spy->sent)->toContain(\BAGArt\TelegramBot\TgApi\Methods\DTO\RestrictChatMemberMethodDTO::class);

    // evaluation snapshot contract: versions stored for replay
    expect($violation->evaluation_snapshot['policyVersion'])->toBe('antispam.policy.v1');
});

it('is idempotent on webhook retry: same message в†’ 1 violation, 1 strike', function () {
    $spy = senderSpy();
    $pipeline = pipelineWith($spy);
    $botConfig = new TgBotConfig(token: 'x:token', botId: 'test_bot');

    $pipeline->handle(antispamMessage(100, 42, 'join t.me/spam_channel now'), $botConfig);
    $pipeline->handle(antispamMessage(100, 42, 'join t.me/spam_channel now'), $botConfig);

    expect(\BAGArt\TelegramBotAntispam\Models\AntispamViolation::query()->count())->toBe(1)
        ->and(\BAGArt\TelegramBotAntispam\Models\AntispamStrikeEvent::query()->count())->toBe(1);
});

it('escalates strikes for burst offenses and decays after quiet period', function () {
    $pipeline = pipelineWith(senderSpy());
    $botConfig = new TgBotConfig(token: 'x:token', botId: 'test_bot');

    $spamText = 'join t.me/spam_channel now';
    foreach ([11, 12] as $messageId) {
        $pipeline->handle(antispamMessage(100, 42, $spamText, $messageId), $botConfig);
    }

    // 2nd offense escalates the ladder
    $consequences = \BAGArt\TelegramBotAntispam\Models\AntispamStrikeEvent::query()
        ->orderBy('created_at')
        ->pluck('strike_consequence')->all();
    expect($consequences)->toBe(['mute_1h', 'mute_6h']);

    // decay: expired strikes stop counting; next offense restarts at mute_1h
    \BAGArt\TelegramBotAntispam\Models\AntispamStrikeEvent::query()->update([
        'expired_at' => now()->subDay(),
        'active' => false,
    ]);

    $pipeline->handle(antispamMessage(100, 42, $spamText, 13), $botConfig);

    $latest = \BAGArt\TelegramBotAntispam\Models\AntispamStrikeEvent::query()
        ->orderByDesc('created_at')->first();
    expect($latest->strike_consequence)->toBe('mute_1h');
});

it('keeps clean messages at zero DB writes', function () {
    $pipeline = pipelineWith(senderSpy());
    DB::enableQueryLog();

    $outcome = $pipeline->handle(
        antispamMessage(100, 42, 'hello everyone, nice weather today'),
        new TgBotConfig(token: 'x:token', botId: 'test_bot'),
    );

    expect($outcome->allows())->toBeTrue()
        ->and(\BAGArt\TelegramBotAntispam\Models\AntispamViolation::query()->count())->toBe(0);
});

it('bypasses module entirely for whitelisted users (no counters, no violations)', function () {
    \BAGArt\TelegramBotAntispam\Models\AntispamUserListEntry::factory()
        ->whitelisted('test_bot', 100, 42)->create();
    app(\BAGArt\TelegramBotAntispam\UserList\UserListManager::class)->refresh('test_bot', 100);

    $pipeline = pipelineWith(senderSpy());

    $outcome = $pipeline->handle(
        antispamMessage(100, 42, 'join t.me/spam_channel now'),
        new TgBotConfig(token: 'x:token', botId: 'test_bot'),
    );

    expect($outcome)->toBeNull()
        ->and(\BAGArt\TelegramBotAntispam\Models\AntispamViolation::query()->count())->toBe(0);
});

it('observes but does not enforce for blacklisted users (bypass enforcement)', function () {
    \BAGArt\TelegramBotAntispam\Models\AntispamUserListEntry::factory()
        ->blacklisted('test_bot', 100, 42)->create();
    app(\BAGArt\TelegramBotAntispam\UserList\UserListManager::class)->refresh('test_bot', 100);

    $spy = senderSpy();
    $pipeline = pipelineWith($spy);

    $outcome = $pipeline->handle(
        antispamMessage(100, 42, 'join t.me/spam_channel now'),
        new TgBotConfig(token: 'x:token', botId: 'test_bot'),
    );

    // observation happened, verdict computed вЂ” no violation, no actions
    expect($outcome)->not->toBeNull()
        ->and($outcome->allows())->toBeFalse()
        ->and(\BAGArt\TelegramBotAntispam\Models\AntispamViolation::query()->count())->toBe(0)
        ->and($spy->sent)->toBe([]);
});
