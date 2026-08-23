<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Database\Factories;

use BAGArt\TelegramBotAntispam\Models\AntispamAppeal;
use BAGArt\TelegramBotAntispam\Models\AntispamViolation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AntispamAppeal> */
class AntispamAppealFactory extends Factory
{
    protected $model = AntispamAppeal::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'violation_id' => AntispamViolation::factory(),
            'user_id' => 42,
            'message' => 'It was not spam',
            'status' => 'pending',
        ];
    }
}
