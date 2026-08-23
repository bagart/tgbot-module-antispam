<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $list_type
 * @property string|null $bot_id
 * @property int $chat_id
 * @property int $user_id
 * @property string|null $reason
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property string|null $created_by
 */
final class AntispamUserListEntry extends Model
{
    use HasFactory;
    use HasUuids;
    final public const TYPE_WHITELIST = 'whitelist';

    final public const TYPE_BLACKLIST = 'blacklist';

    protected $table = 'antispam_user_list_entries';

    protected $fillable = [
        'list_type',
        'bot_id',
        'chat_id',
        'user_id',
        'reason',
        'expires_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }


    protected static function newFactory(): Factory
    {
        return \BAGArt\TelegramBotAntispam\Database\Factories\AntispamUserListEntryFactory::new();
    }
}
