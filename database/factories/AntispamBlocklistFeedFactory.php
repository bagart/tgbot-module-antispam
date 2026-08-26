<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Database\Factories;

use BAGArt\TelegramBotAntispam\Models\AntispamBlocklistFeed;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AntispamBlocklistFeed> */
class AntispamBlocklistFeedFactory extends Factory
{
    protected $model = AntispamBlocklistFeed::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'source_bot_id' => 'bot_a',
            'user_id' => $this->faker->numberBetween(1, 100000),
            'reason' => 'advertising.regex',
            'published_at' => now(),
        ];
    }

    public function fromSource(string $botId, int $userId): static
    {
        return $this->state(fn (): array => [
            'source_bot_id' => $botId,
            'user_id' => $userId,
        ]);
    }
}
