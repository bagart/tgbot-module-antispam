<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Daily aggregated counters per (date, bot, chat, group).
 *
 * @property string $id
 * @property string $stat_date
 * @property string $bot_id
 * @property int|null $chat_id
 * @property string|null $group_id
 * @property int $detections
 * @property int $violations
 */
final class AntispamStat extends Model
{
    use HasFactory;
    use HasUuids;

    // antispam_stats carries no created_at/updated_at columns
    public $timestamps = false;

    protected $table = 'antispam_stats';

    protected $fillable = [
        'stat_date',
        'bot_id',
        'chat_id',
        'group_id',
        'detections',
        'violations',
    ];

    protected function casts(): array
    {
        return [
            'stat_date' => 'date:Y-m-d',
            'detections' => 'integer',
            'violations' => 'integer',
        ];
    }


    protected static function newFactory(): Factory
    {
        return \BAGArt\TelegramBotAntispam\Database\Factories\AntispamStatFactory::new();
    }
}
