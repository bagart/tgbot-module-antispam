<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $violation_id
 * @property int $user_id
 * @property string|null $message
 * @property string $status
 * @property string|null $decided_by
 * @property \Illuminate\Support\Carbon|null $decided_at
 */
final class AntispamAppeal extends Model
{
    use HasFactory;
    use HasUuids;

    final public const STATUS_PENDING = 'pending';

    final public const STATUS_APPROVED = 'approved';

    final public const STATUS_REJECTED = 'rejected';

    /** @var list<string> */
    final public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    protected $table = 'antispam_appeals';

    protected $fillable = [
        'violation_id',
        'user_id',
        'message',
        'status',
        'decided_by',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    public function violation(): BelongsTo
    {
        return $this->belongsTo(AntispamViolation::class, 'violation_id');
    }

    protected static function newFactory(): Factory
    {
        return \BAGArt\TelegramBotAntispam\Database\Factories\AntispamAppealFactory::new();
    }
}
