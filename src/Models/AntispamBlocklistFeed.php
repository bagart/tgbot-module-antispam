<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One published ban: (source bot, user). Subscriber bots ingest these rows
 * through antispam:blocklist:sync into per-chat blacklist entries.
 *
 * @property string $id
 * @property string $source_bot_id
 * @property int $user_id
 * @property string|null $reason
 * @property \Illuminate\Support\Carbon $published_at
 */
final class AntispamBlocklistFeed extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'antispam_blocklist_feed';

    protected $fillable = [
        'source_bot_id',
        'user_id',
        'reason',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    protected static function newFactory(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return \BAGArt\TelegramBotAntispam\Database\Factories\AntispamBlocklistFeedFactory::new();
    }
}
