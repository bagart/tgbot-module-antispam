<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Database\Factories;

use BAGArt\TelegramBotAntispam\Models\AntispamRuleModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AntispamRuleModel> */
class AntispamRuleModelFactory extends Factory
{
    protected $model = AntispamRuleModel::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'bot_id' => null,
            'name' => $this->faker->unique()->slug(),
            'group_id' => 'advertising',
            'type' => 'regex',
            'config' => null,
            'score_weight' => 10,
            'severity' => 'low',
            'kind' => 'soft',
            'priority' => 100,
            'is_active' => true,
            'cooldown_seconds' => null,
            'created_by' => 'seeder',
        ];
    }

    public function forBot(string $botId): static
    {
        return $this->state(fn (): array => ['bot_id' => $botId]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
