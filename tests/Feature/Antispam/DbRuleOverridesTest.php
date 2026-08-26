<?php

declare(strict_types=1);

use BAGArt\TelegramBotAntispam\Models\AntispamRuleModel;
use BAGArt\TelegramBotManagement\Models\TgModuleEnablement;

require_once __DIR__.'/AntispamHelpers.php';

beforeEach(function () {
    config(['antispam.counter_driver' => 'memory']);

    \BAGArt\TelegramBotManagement\Models\TgBot::create(['bot_id' => 'test_bot', 'token' => 't:token']);
    TgModuleEnablement::factory()->forChat('test_bot', 100)->enabled(true)->create(['module_id' => 'antispam']);
});

it('disables a built-in rule via an inactive DB row', function () {
    AntispamRuleModel::query()->create([
        'bot_id' => null,
        'name' => 'advertising.regex',
        'group_id' => 'advertising',
        'type' => 'regex',
        'score_weight' => 60,
        'severity' => 'high',
        'kind' => 'hard',
        'is_active' => false,
    ]);

    $pipeline = pipelineWith(senderSpy());

    $outcome = $pipeline->handle(
        antispamMessage(100, 42, 'join t.me/spam_channel now'),
        new \BAGArt\TelegramBot\Configs\TgBotConfig(token: 'x:token', botId: 'test_bot'),
    );

    expect($outcome->plan->isEnabled('advertising.regex'))->toBeFalse()
        ->and($outcome->detections)->toBe([])
        ->and($outcome->allows())->toBeTrue()
        ->and(\BAGArt\TelegramBotAntispam\Models\AntispamViolation::query()->count())->toBe(0);
});

it('overrides rule score from an active DB row and stores it in the violation', function () {
    AntispamRuleModel::query()->create([
        'bot_id' => 'test_bot',
        'name' => 'advertising.regex',
        'group_id' => 'advertising',
        'type' => 'regex',
        // score raised to the ban threshold (150) — group cap 80 still applies
        'score_weight' => 150,
        'severity' => 'critical',
        'kind' => 'hard',
        'is_active' => true,
    ]);

    $spy = senderSpy();
    $pipeline = pipelineWith($spy);

    $outcome = $pipeline->handle(
        antispamMessage(100, 42, 'join t.me/spam_channel now'),
        new \BAGArt\TelegramBot\Configs\TgBotConfig(token: 'x:token', botId: 'test_bot'),
    );

    $violation = \BAGArt\TelegramBotAntispam\Models\AntispamViolation::query()->sole();
    expect($violation->enforcement_action)->toBe('ban')
        ->and($violation->matched_rules[0]['score'])->toBe(150)
        ->and($outcome->verdict->action->value)->toBe('ban');

    // ban enforcement went out through the outbound-bound sender
    expect($spy->sent)->toContain(\BAGArt\TelegramBot\TgApi\Methods\DTO\BanChatMemberMethodDTO::class);
});

it('keeps compiled plan cached per ruleset version across messages', function () {
    $compiler = app(\BAGArt\TelegramBotAntispam\Engine\PolicyCompiler::class);
    $overrides = app(\BAGArt\TelegramBotAntispam\Engine\DbRuleOverrides::class)->forBot('test_bot');

    $first = $compiler->compile('test_bot', 100, $overrides);
    $second = $compiler->compile('test_bot', 100, $overrides);

    expect($second)->toBe($first)
        ->and($first->rulesetVersion)->toBe($first->rulesetVersion);
});
