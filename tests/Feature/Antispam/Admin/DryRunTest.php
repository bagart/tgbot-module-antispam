<?php

declare(strict_types=1);

require_once __DIR__.'/AdminHelpers.php';

beforeEach(function () {
    antispamAdminSetup();
    antispamAdminActingAs();
});

it('returns the full breakdown for a spam text', function () {
    $report = antispamDryRun('join t.me/spam_channel now, fast $$$');

    expect($report['policyVersion'])->toBe('antispam.policy.v1')
        ->and($report['rulesetVersion'])->not->toBe('')
        ->and($report['score'])->toBeGreaterThan(0)
        ->and($report['globalCap'])->toBe(200)
        ->and($report['thresholds'])->toBe(['warn' => 40, 'restrict' => 80, 'ban' => 150])
        ->and(collect($report['matchedRules'])->pluck('ruleId'))->toContain('advertising.regex')
        ->and($report['groupBreakdown']['advertising']['contribution'])->toBeGreaterThan(0)
        ->and($report['verdict']['action'])->toBe('restrict');
});

it('returns an allow verdict for clean text', function () {
    $report = antispamDryRun('hey folks, how was your weekend?');

    expect($report['score'])->toBe(0)
        ->and($report['matchedRules'])->toBe([])
        ->and($report['verdict']['action'])->toBe('warn');
});

it('validates the input', function () {
    $this->postJson(route('antispam.dry-run'), [
        'bot_id' => 'admin_bot',
        'chat_id' => 100,
    ])->assertUnprocessable()->assertJsonValidationErrors(['text']);
});
