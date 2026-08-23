<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $violation_id
 * @property string $bot_id
 * @property int $chat_id
 * @property int $user_id
 * @property string $strike_consequence
 * @property \Illuminate\Support\Carbon|null $expired_at
 * @property bool $active
 */
final class AntispamStrikeEvent extends Model
{
    use HasUuids;

    protected $table = 'antispam_strike_events';

    protected $fillable = [
        'violation_id',
        'bot_id',
        'chat_id',
        'user_id',
        'strike_consequence',
        'expired_at',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'expired_at' => 'datetime',
            'active' => 'boolean',
        ];
    }
}
