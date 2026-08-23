<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Database\Factories;

use BAGArt\TelegramBotAntispam\Models\AntispamUserStrikes;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AntispamUserStrikes> */
class AntispamUserStrikesFactory extends Factory
{
    protected $model = AntispamUserStrikes::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'bot_id' => 'bot_a',
            'chat_id' => 100,
            'user_id' => 42,
            'active_strikes' => 0,
            'total_strikes' => 0,
        ];
    }

    public function forScope(string $botId, int $chatId, int $userId): static
    {
        return $this->state(fn (): array => [
            'bot_id' => $botId,
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }
}
