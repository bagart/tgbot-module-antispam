<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $bot_id
 * @property int $chat_id
 * @property int $user_id
 * @property int $message_id
 * @property array $message_snapshot
 * @property array $matched_rules
 * @property array $group_breakdown
 * @property array|null $risk_context
 * @property array $evaluation_snapshot
 * @property int $score
 * @property array $verdict
 * @property string $enforcement_action
 * @property string $status
 */
final class AntispamViolation extends Model
{
    use HasFactory;
    use HasUuids;

    final public const STATUS_PENDING = 'pending';

    final public const STATUS_APPLIED = 'applied';

    final public const STATUS_OVERTURNED = 'overturned';

    final public const STATUS_ESCALATED = 'escalated';

    /** @var list<string> */
    final public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPLIED,
        self::STATUS_OVERTURNED,
        self::STATUS_ESCALATED,
    ];

    protected $table = 'antispam_violations';

    protected $fillable = [
        'bot_id',
        'chat_id',
        'user_id',
        'message_id',
        'message_snapshot',
        'matched_rules',
        'group_breakdown',
        'risk_context',
        'evaluation_snapshot',
        'score',
        'verdict',
        'enforcement_action',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'message_snapshot' => 'array',
            'matched_rules' => 'array',
            'group_breakdown' => 'array',
            'risk_context' => 'array',
            'evaluation_snapshot' => 'array',
            'score' => 'integer',
            'verdict' => 'array',
        ];
    }


    protected static function newFactory(): Factory
    {
        return \BAGArt\TelegramBotAntispam\Database\Factories\AntispamViolationFactory::new();
    }
}
