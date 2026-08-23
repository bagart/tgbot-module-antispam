<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Database\Factories;

use BAGArt\TelegramBotAntispam\Domain\StrikeConsequence;
use BAGArt\TelegramBotAntispam\Models\AntispamStrikeEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AntispamStrikeEvent> */
class AntispamStrikeEventFactory extends Factory
{
    protected $model = AntispamStrikeEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'bot_id' => 'bot_a',
            'chat_id' => 100,
            'user_id' => 42,
            'strike_consequence' => StrikeConsequence::Mute1h->value,
            'expired_at' => now()->addHour(),
            'active' => true,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'expired_at' => now()->addDay(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'active' => false,
            'expired_at' => now()->subDay(),
        ]);
    }
}
