<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Commands;

use BAGArt\TelegramBotAntispam\Models\AntispamBlocklistFeed;
use BAGArt\TelegramBotAntispam\Models\AntispamUserListEntry;
use BAGArt\TelegramBot\Contracts\Modules\ModuleEnablementContract;
use BAGArt\TelegramBot\Contracts\Modules\ModuleSettingsContract;
use BAGArt\TelegramBotManagement\Models\TgBot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * Federated blocklist ingestion (todo.antispam.md P3.7): every ban published
 * by source bots becomes a blacklist entry (with source mark + expiry) in
 * each antispam-enabled chat of every subscriber bot that opted in via its
 * bot-scope module_settings: {"blocklist_sync": {"enabled": true}}.
 */
final class BlocklistSyncCommand extends Command
{
    protected $signature = 'antispam:blocklist:sync {--bot= : Sync only this subscriber bot}';

    protected $description = 'Ingest federated blocklist bans into subscriber bot blacklists';

    public function handle(): int
    {
        $retentionDays = max(1, (int) Config::get('antispam.blocklist.retention_days', 30));
        $expiresAt = now()->addDays($retentionDays);

        $settings = app(ModuleSettingsContract::class);
        $enablements = app(ModuleEnablementContract::class);

        $subscribers = TgBot::query()
            ->when($this->option('bot') !== null, fn ($q) => $q->where('bot_id', (string) $this->option('bot')))
            ->pluck('bot_id');

        $feed = AntispamBlocklistFeed::query()->get();
        if ($feed->isEmpty()) {
            $this->info('Blocklist feed is empty — nothing to sync.');

            return self::SUCCESS;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($subscribers as $botId) {
            if (! $this->optedIn($settings, (string) $botId)) {
                ++$skipped;

                continue;
            }

            $chatIds = collect($settings->chatsWithSettings('antispam', (string) $botId))
                ->filter(fn (array $row): bool => $row['chatId'] !== null)
                ->filter(fn (array $row): bool => $enablements->isEnabled('antispam', (string) $botId, (int) $row['chatId']))
                ->map(fn (array $row): int => (int) $row['chatId'])
                ->values();

            foreach ($feed as $entry) {
                // never ingest a bot's own bans back into itself
                if ((string) $entry->source_bot_id === (string) $botId) {
                    continue;
                }

                foreach ($chatIds as $chatId) {
                    $reason = 'blocklist:'.$entry->source_bot_id;
                    $existing = AntispamUserListEntry::query()
                        ->where('bot_id', $botId)
                        ->where('chat_id', $chatId)
                        ->where('user_id', $entry->user_id)
                        ->where('list_type', 'blacklist')
                        ->first();

                    if ($existing === null) {
                        AntispamUserListEntry::query()->create([
                            'bot_id' => $botId,
                            'chat_id' => $chatId,
                            'user_id' => $entry->user_id,
                            'list_type' => 'blacklist',
                            'reason' => $reason,
                            'expires_at' => $expiresAt,
                            'created_by' => 'antispam:blocklist',
                        ]);
                        ++$created;

                        continue;
                    }

                    if ($existing->reason !== $reason || $existing->expires_at?->lt(now())) {
                        $existing->fill([
                            'reason' => $reason,
                            'expires_at' => $expiresAt,
                            'created_by' => 'antispam:blocklist',
                        ])->save();
                        ++$updated;

                        continue;
                    }

                    // dedupe: identical entry already present, refresh silently
                    ++$updated;
                }
            }
        }

        $this->info("Blocklist sync done: {$created} created, {$updated} refreshed, {$skipped} bots skipped (not opted in).");

        return self::SUCCESS;
    }

    private function optedIn(ModuleSettingsContract $settings, string $botId): bool
    {
        $sync = $settings->settingsFor('antispam', $botId)['blocklist_sync'] ?? null;

        return is_array($sync) && ($sync['enabled'] ?? false) === true;
    }
}
