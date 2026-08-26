<?php

declare(strict_types=1);

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBotManagement\Models\TgModuleEnablement;
use BAGArt\TelegramBotAntispam\Models\AntispamViolation;

require_once __DIR__.'/AntispamHelpers.php';

beforeEach(function () {
    config(['antispam.counter_driver' => 'memory']);

    \BAGArt\TelegramBotManagement\Models\TgBot::create(['bot_id' => 'test_bot', 'token' => 't:token']);
});

it('enforces a honeypot hit as a hard violation regardless of score', function () {
    TgModuleEnablement::factory()->forChat('test_bot', 100)->enabled(true)->create([
        'module_id' => 'antispam',
        'module_settings' => ['honeypot' => ['words' => ['trap-phrase']]],
    ]);

    $spy = senderSpy();
    $pipeline = pipelineWith($spy);
    $botConfig = new TgBotConfig(token: 'x:token', botId: 'test_bot');

    // Innocuous-looking message (zero rule score) but contains the trigger
    $outcome = $pipeline->handle(antispamMessage(100, 42, 'hello friends, check trap-phrase please'), $botConfig);

    expect($outcome)->not->toBeNull()
        ->and($outcome->allows())->toBeFalse();

    $violation = AntispamViolation::query()->sole();
    expect($violation->enforcement_action)->toBe('restrict')
        ->and($spy->sent)->toContain(\BAGArt\TelegramBot\TgApi\Methods\DTO\RestrictChatMemberMethodDTO::class);

    $ruleIds = array_column((array) $violation->matched_rules, 'ruleId');
    expect($ruleIds)->toContain('honeypot.trigger');
});

it('stays silent for messages without a configured honeypot word', function () {
    TgModuleEnablement::factory()->forChat('test_bot', 100)->enabled(true)->create([
        'module_id' => 'antispam',
        'module_settings' => ['honeypot' => ['words' => ['trap-phrase']]],
    ]);

    $pipeline = pipelineWith(senderSpy());

    $outcome = $pipeline->handle(
        antispamMessage(100, 42, 'hello friends, nice weather today'),
        new TgBotConfig(token: 'x:token', botId: 'test_bot'),
    );

    expect($outcome?->allows())->toBeTrue()
        ->and(AntispamViolation::query()->count())->toBe(0);
});

it('feeds cross-bot reputation into risk via the blocklist feed', function () {
    // Two other bots banned this user → reputation signal raises risk to HIGH;
    // with default transitions HIGH + at_score 70 → restrict for scored spam.
    \BAGArt\TelegramBotManagement\Models\TgBot::create(['bot_id' => 'other_bot_1', 'token' => 't:o1']);
    \BAGArt\TelegramBotManagement\Models\TgBot::create(['bot_id' => 'other_bot_2', 'token' => 't:o2']);
    \BAGArt\TelegramBotAntispam\Models\AntispamBlocklistFeed::factory()->fromSource('other_bot_1', 42)->create();
    \BAGArt\TelegramBotAntispam\Models\AntispamBlocklistFeed::factory()->fromSource('other_bot_2', 42)->create();

    TgModuleEnablement::factory()->forChat('test_bot', 100)->enabled(true)->create([
        'module_id' => 'antispam',
        // low thresholds so a mild soft detection lands in the warn band only
        'module_settings' => ['thresholds' => ['warn' => 20, 'restrict' => 500, 'ban' => 900]],
    ]);

    $pipeline = pipelineWith(senderSpy());
    $botConfig = new TgBotConfig(token: 'x:token', botId: 'test_bot');

    $outcome = $pipeline->handle(antispamMessage(100, 42, str_repeat('x', 4200)), $botConfig);

    // flood.size scores 20 (warn band), but reputation=2 → risk HIGH →
    // transition at_score>=20... wait: default transition at_score=70 > 20,
    // so warn stands; assert risk context was built with HIGH level.
    expect($outcome)->not->toBeNull()
        ->and($outcome->risk?->level)->toBe('high');
});
