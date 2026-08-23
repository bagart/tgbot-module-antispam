<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Database\Factories;

use BAGArt\TelegramBotAntispam\Models\AntispamStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AntispamStat> */
class AntispamStatFactory extends Factory
{
    protected $model = AntispamStat::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'stat_date' => now()->toDateString(),
            'bot_id' => 'bot_a',
            'chat_id' => 100,
            'group_id' => null,
            'detections' => 0,
            'violations' => 0,
        ];
    }

    public function forGroup(string $groupId): static
    {
        return $this->state(fn (): array => ['group_id' => $groupId]);
    }
}
