<?php

declare(strict_types=1);

use BAGArt\TelegramBotAntispam\Http\Controllers\AntispamAnalyticsController;
use BAGArt\TelegramBotAntispam\Models\AntispamStat;
use BAGArt\TelegramBotAntispam\Models\AntispamViolation;

require_once __DIR__.'/AdminHelpers.php';

beforeEach(function () {
    config(['antispam.counter_driver' => 'memory']);
});

function analyticsViolation(array $overrides = []): AntispamViolation
{
    return AntispamViolation::factory()->forScope('admin_bot', 100, 42)->create($overrides);
}

it('buckets heatmap cells by weekday and hour', function () {
    // Tuesday 2026-08-25 14:30 local — two violations in the same hour cell
    analyticsViolation(['created_at' => '2026-08-25 14:30:00']);
    analyticsViolation(['created_at' => '2026-08-25 14:50:00']);
    // Monday 09:00
    analyticsViolation(['created_at' => '2026-08-24 09:00:00']);
    // Outside the window must be ignored
    analyticsViolation(['created_at' => now()->subDays(60)]);

    $grid = AntispamAnalyticsController::heatmap(30);

    expect(count($grid))->toBe(7)
        ->and(count($grid[0]))->toBe(24)
        ->and($grid[1][14])->toBe(2)   // Tue (index 1), 14:00 hour
        ->and($grid[0][9])->toBe(1);   // Mon, 09:00
});

it('ranks top matched rules across violations', function () {
    $burst = ['ruleId' => 'flood.rate.burst', 'score' => 30, 'severity' => 'high', 'kind' => 'soft', 'group' => 'flood', 'reason' => 'rate'];
    $contacts = ['ruleId' => 'advertising.contacts', 'score' => 60, 'severity' => 'high', 'kind' => 'hard', 'group' => 'advertising', 'reason' => 'contact'];

    analyticsViolation(['matched_rules' => [$burst]]);
    analyticsViolation(['matched_rules' => [$burst]]);
    analyticsViolation(['matched_rules' => [$contacts]]);

    $top = AntispamAnalyticsController::topRules(30, 10);

    expect($top[0])->toMatchArray(['ruleId' => 'flood.rate.burst', 'count' => 2])
        ->and($top[1]['ruleId'])->toBe('advertising.contacts')
        ->and($top[1]['count'])->toBe(1);
});

it('aggregates group contribution from the daily stats rollup', function () {
    AntispamStat::factory()->forGroup('flood')->create([
        'stat_date' => today(),
        'violations' => 5,
        'detections' => 7,
    ]);
    AntispamStat::factory()->forGroup('flood')->create([
        'stat_date' => today(),
        'chat_id' => 200,
        'violations' => 1,
        'detections' => 2,
    ]);
    AntispamStat::factory()->forGroup('advertising')->create([
        'stat_date' => today(),
        'violations' => 3,
        'detections' => 3,
    ]);

    $groups = AntispamAnalyticsController::groupContribution(7);

    expect($groups[0])->toMatchArray(['groupId' => 'flood', 'violations' => 6, 'detections' => 9])
        ->and($groups[1]['groupId'])->toBe('advertising');
});

it('ranks chats by violation count', function () {
    analyticsViolation();
    analyticsViolation();
    AntispamViolation::factory()->forScope('admin_bot', 200, 43)->create();

    $ranking = AntispamAnalyticsController::chatRanking(30, 10);

    expect($ranking[0])->toMatchArray(['botId' => 'admin_bot', 'chatId' => 100, 'violations' => 2])
        ->and($ranking[1]['chatId'])->toBe(200);
});

it('renders the analytics page', function () {
    antispamAdminSetup();
    antispamAdminActingAs();
    analyticsViolation();

    $response = test()->get(route('antispam.analytics'));

    $response->assertOk();
});
