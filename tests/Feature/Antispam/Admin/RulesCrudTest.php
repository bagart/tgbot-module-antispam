<?php

declare(strict_types=1);

require_once __DIR__.'/AdminHelpers.php';

use BAGArt\TelegramBotAntispam\Models\AntispamRuleModel;

beforeEach(function () {
    antispamAdminSetup();
    antispamAdminActingAs();
});

it('creates, updates and deletes a rule', function () {
    $payload = [
        'bot_id' => null,
        'name' => 'no.crypto',
        'group_id' => 'advertising',
        'type' => 'keyword',
        'config' => ['param' => 1],
        'score_weight' => 45,
        'severity' => 'high',
        'kind' => 'soft',
        'priority' => 50,
        'is_active' => true,
        'cooldown_seconds' => 30,
    ];

    $this->post(route('antispam.rules.store'), $payload)->assertRedirect();

    $rule = AntispamRuleModel::query()->where('name', 'no.crypto')->sole();
    expect($rule->group_id)->toBe('advertising')
        ->and($rule->score_weight)->toBe(45)
        ->and($rule->kind)->toBe('soft')
        ->and($rule->created_by)->not->toBeNull();

    $this->patch(route('antispam.rules.update', $rule), [...$payload, 'score_weight' => 77])->assertRedirect();
    expect($rule->refresh()->score_weight)->toBe(77);

    $this->delete(route('antispam.rules.destroy', $rule))->assertRedirect();
    expect(AntispamRuleModel::query()->where('name', 'no.crypto')->count())->toBe(0);
});

it('rejects invalid rule payloads', function () {
    $this->post(route('antispam.rules.store'), [
        'bot_id' => null,
        'name' => 'bad',
        'group_id' => 'advertising',
        'type' => 'keyword',
        'score_weight' => 45,
        'severity' => 'apocalyptic',
        'kind' => 'soft',
        'priority' => 50,
        'is_active' => true,
    ])->assertSessionHasErrors('severity');
});

it('makes verdicts reflect rule changes without manual cache flushing (dry-run)', function () {
    $spamText = 'join t.me/spam_channel now';
    $before = antispamDryRun($spamText);

    // Disable the advertising pattern rule via a DB row → verdict must change
    AntispamRuleModel::query()->create([
        'bot_id' => null,
        'name' => 'advertising.regex',
        'group_id' => 'advertising',
        'type' => 'url',
        'config' => null,
        'score_weight' => 60,
        'severity' => 'high',
        'kind' => 'hard',
        'priority' => 10,
        'is_active' => false,
        'cooldown_seconds' => null,
    ]);

    $after = antispamDryRun($spamText);

    expect($before['rulesetVersion'])->not->toBe($after['rulesetVersion'])
        ->and(collect($before['matchedRules'])->pluck('ruleId'))->toContain('advertising.regex')
        ->and(collect($after['matchedRules'])->pluck('ruleId'))->not->toContain('advertising.regex')
        ->and($after['verdict']['action'])->toBe('warn');
});

it('makes verdicts reflect score overrides from DB rules', function () {
    AntispamRuleModel::query()->create([
        'bot_id' => null,
        'name' => 'advertising.regex',
        'group_id' => 'advertising',
        'type' => 'url',
        'config' => null,
        'score_weight' => 15,
        'severity' => 'low',
        'kind' => 'soft',
        'priority' => 10,
        'is_active' => true,
        'cooldown_seconds' => null,
    ]);

    $report = antispamDryRun('join t.me/spam_channel now');

    $detection = collect($report['matchedRules'])->firstWhere('ruleId', 'advertising.regex');

    expect($detection['score'])->toBe(15)
        ->and($detection['severity'])->toBe('low')
        ->and($detection['kind'])->toBe('soft')
        ->and($report['verdict']['action'])->toBe('warn');
});
