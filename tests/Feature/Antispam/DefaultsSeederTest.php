<?php

declare(strict_types=1);

use BAGArt\TelegramBotAntispam\Models\AntispamRuleGroup;
use BAGArt\TelegramBotAntispam\Database\Seeders\AntispamDefaultsSeeder;

it('seeds default rule groups idempotently', function () {
    $this->seed(AntispamDefaultsSeeder::class);

    $caps = AntispamRuleGroup::query()->whereNull('bot_id')->pluck('cap', 'group_id');

    expect($caps['advertising'])->toBe(80)
        ->and($caps['flood'])->toBe(100);

    // second run must not duplicate
    $this->seed(AntispamDefaultsSeeder::class);

    expect(AntispamRuleGroup::query()->whereNull('bot_id')->count())->toBe(2);
});
