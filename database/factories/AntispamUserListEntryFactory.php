<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Database\Factories;

use BAGArt\TelegramBotAntispam\Models\AntispamUserListEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AntispamUserListEntry> */
class AntispamUserListEntryFactory extends Factory
{
    protected $model = AntispamUserListEntry::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'list_type' => 'whitelist',
            'bot_id' => 'bot_a',
            'chat_id' => 100,
            'user_id' => 42,
            'reason' => null,
            'expires_at' => null,
        ];
    }

    public function whitelisted(string $botId, int $chatId, int $userId): static
    {
        return $this->state(fn (): array => [
            'list_type' => 'whitelist',
            'bot_id' => $botId,
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    public function blacklisted(string $botId, int $chatId, int $userId): static
    {
        return $this->state(fn (): array => [
            'list_type' => 'blacklist',
            'bot_id' => $botId,
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }
}
