<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Aggregate cache per (bot, chat, user). `active_strikes` is a CACHE ONLY
 * value — escalation always counts from strike_events where expired_at > now().
 *
 * @property string $id
 * @property string $bot_id
 * @property int $chat_id
 * @property int $user_id
 * @property int $active_strikes
 * @property int $total_strikes
 */
final class AntispamUserStrikes extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'antispam_user_strikes';

    protected $fillable = [
        'bot_id',
        'chat_id',
        'user_id',
        'active_strikes',
        'total_strikes',
        'last_offense_at',
        'last_violation_id',
        'muted_until',
        'restricted_until',
        'banned_at',
    ];

    protected function casts(): array
    {
        return [
            'active_strikes' => 'integer',
            'total_strikes' => 'integer',
            'last_offense_at' => 'datetime',
            'muted_until' => 'datetime',
            'restricted_until' => 'datetime',
            'banned_at' => 'datetime',
        ];
    }

    /** Factory lives in the root app (Database\Factories), not inside the module. */
    protected static function newFactory(): Factory
    {
        return \Database\Factories\AntispamUserStrikesFactory::new();
    }
}
