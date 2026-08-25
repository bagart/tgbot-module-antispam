<?php

declare(strict_types=1);

require_once __DIR__.'/AdminHelpers.php';

use BAGArt\TelegramBotManagement\Models\TgModuleEnablement;

beforeEach(function () {
    antispamAdminSetup();
    antispamAdminActingAs();
});

it('saves and loads chat settings', function () {
    $response = $this->put(route('antispam.chats.updateSettings', ['botId' => 'admin_bot', 'chatId' => 100]), [
        'strictness' => 'strict',
        'thresholds' => ['warn' => 20, 'restrict' => 40, 'ban' => 80],
        'group_caps' => ['advertising' => 50],
        'custom_rules' => ['advertising.regex', 'flood.rate.burst'],
    ]);

    $response->assertRedirect();

    $row = TgModuleEnablement::query()
        ->where('module_id', 'antispam')
        ->where('bot_id', 'admin_bot')
        ->where('chat_id', 100)
        ->sole();

    expect($row->module_settings['strictness'])->toBe('strict')
        ->and($row->module_settings['thresholds'])->toBe(['warn' => 20, 'restrict' => 40, 'ban' => 80])
        ->and($row->module_settings['group_caps'])->toBe(['advertising' => 50]);
});

it('rejects an empty custom_rules allowlist (would disable everything)', function () {
    $this->from('/antispam/chats')
        ->put(route('antispam.chats.updateSettings', ['botId' => 'admin_bot', 'chatId' => 100]), [
            'custom_rules' => [],
        ])
        ->assertSessionHasErrors('custom_rules');
});

it('rejects unknown rule ids in custom_rules', function () {
    $this->put(route('antispam.chats.updateSettings', ['botId' => 'admin_bot', 'chatId' => 100]), [
        'custom_rules' => ['not.a.rule'],
    ])->assertSessionHasErrors('custom_rules');
});

it('compiles the plan from saved settings: rulesetVersion changes and strict thresholds apply', function () {
    $before = antispamDryRun('join t.me/spam_channel now');

    $this->put(route('antispam.chats.updateSettings', ['botId' => 'admin_bot', 'chatId' => 100]), [
        'strictness' => 'strict',
    ])->assertRedirect();

    $after = antispamDryRun('join t.me/spam_channel now');

    expect($before['rulesetVersion'])->not->toBe($after['rulesetVersion'])
        // strict preset = [24, 48, 90]
        ->and($after['thresholds'])->toBe(['warn' => 24, 'restrict' => 48, 'ban' => 90]);
});

it('custom_rules allowlist disables non-listed rules in the compiled plan', function () {
    $report = antispamDryRun('join t.me/spam_channel now');
    expect(collect($report['matchedRules'])->pluck('ruleId'))->toContain('advertising.regex');

    // Allowlist only a flood rule → the advertising detection must disappear
    $this->put(route('antispam.chats.updateSettings', ['botId' => 'admin_bot', 'chatId' => 100]), [
        'custom_rules' => ['flood.rate.burst'],
    ])->assertRedirect();

    $after = antispamDryRun('join t.me/spam_channel now');

    expect(collect($after['matchedRules'])->pluck('ruleId'))->not->toContain('advertising.regex')
        ->and($after['verdict']['action'])->toBe('warn');
});

it('clearing custom rules restores inheritance (all active)', function () {
    $this->put(route('antispam.chats.updateSettings', ['botId' => 'admin_bot', 'chatId' => 100]), [
        'custom_rules' => ['flood.rate.burst'],
    ])->assertRedirect();

    $this->put(route('antispam.chats.updateSettings', ['botId' => 'admin_bot', 'chatId' => 100]), [
        'custom_rules' => null,
    ])->assertRedirect();

    $report = antispamDryRun('join t.me/spam_channel now');

    expect(collect($report['matchedRules'])->pluck('ruleId'))->toContain('advertising.regex');
});
