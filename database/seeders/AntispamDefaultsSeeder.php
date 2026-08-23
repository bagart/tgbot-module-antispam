<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Database\Seeders;

use BAGArt\TelegramBotAntispam\Models\AntispamRuleGroup;
use Illuminate\Database\Seeder;

/**
 * Default anti-spam rule groups and score caps (RFC v5.3):
 * advertising 80 / flood 100; the global cap (200) lives in policy settings.
 */
class AntispamDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            ['group_id' => 'advertising', 'title' => 'Advertising & contacts', 'cap' => 80],
            ['group_id' => 'flood', 'title' => 'Flood & rate abuse', 'cap' => 100],
        ];

        foreach ($groups as $group) {
            AntispamRuleGroup::query()->firstOrCreate(
                ['group_id' => $group['group_id'], 'bot_id' => null],
                $group,
            );
        }
    }
}
