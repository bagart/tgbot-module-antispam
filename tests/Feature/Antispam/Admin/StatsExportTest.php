<?php

declare(strict_types=1);

require_once __DIR__.'/AdminHelpers.php';

use BAGArt\TelegramBotAntispam\Models\AntispamStat;

beforeEach(function () {
    antispamAdminSetup();
    antispamAdminActingAs();
});

function seedStats(): void
{
    $rows = [
        ['stat_date' => today(), 'bot_id' => 'admin_bot', 'chat_id' => 100, 'group_id' => 'advertising', 'detections' => 10, 'violations' => 4],
        ['stat_date' => today(), 'bot_id' => 'admin_bot', 'chat_id' => 100, 'group_id' => 'flood', 'detections' => 5, 'violations' => 1],
        ['stat_date' => today()->subDay(), 'bot_id' => 'admin_bot', 'chat_id' => 200, 'group_id' => null, 'detections' => 7, 'violations' => 2],
    ];

    foreach ($rows as $row) {
        AntispamStat::query()->create([...$row, 'id' => (string) Illuminate\Support\Str::uuid()]);
    }
}

it('aggregates daily stats accurately', function () {
    seedStats();

    $response = $this->getJson(route('antispam.stats.index'));
    $response->assertOk();

    $daily = collect($response->json('daily'))->pluck(null, 'date');

    expect($daily)->toHaveCount(2)
        ->and($daily[today()->toDateString()]['detections'])->toBe(15)
        ->and($daily[today()->toDateString()]['violations'])->toBe(5)
        ->and($daily[today()->subDay()->toDateString()]['detections'])->toBe(7)
        ->and($daily[today()->subDay()->toDateString()]['violations'])->toBe(2);
});

it('exports csv', function () {
    seedStats();

    $response = $this->get(route('antispam.stats.export', ['format' => 'csv']));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    $content = trim((string) $response->streamedContent());
    $lines = explode("\n", $content);

    expect($lines[0])->toBe('date,detections,violations')
        ->and($lines)->toHaveCount(3)
        ->and($content)->toContain(today()->toDateString().',15,5');
});

it('exports json', function () {
    seedStats();

    $response = $this->get(route('antispam.stats.export', ['format' => 'json']));
    $response->assertOk();

    $decoded = json_decode((string) $response->streamedContent(), true);

    expect($decoded)->toBeArray()->toHaveCount(2)
        ->and(collect($decoded)->sum('violations'))->toBe(7);
});

it('rejects unknown export formats', function () {
    $this->get(route('antispam.stats.export', ['format' => 'xml']))->assertSessionHasErrors('format');
});
