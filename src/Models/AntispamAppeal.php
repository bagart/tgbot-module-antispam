<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $violation_id
 * @property int $user_id
 * @property string|null $message
 * @property string $status
 */
final class AntispamAppeal extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'antispam_appeals';

    protected $fillable = [
        'violation_id',
        'user_id',
        'message',
        'status',
    ];

    /** Factory lives in the root app (Database\Factories), not inside the module. */
    protected static function newFactory(): Factory
    {
        return \Database\Factories\AntispamAppealFactory::new();
    }
}
