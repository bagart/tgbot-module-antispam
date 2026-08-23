<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string|null $bot_id
 * @property string $name
 * @property string $group_id
 * @property string $type
 * @property array|null $config
 * @property int $score_weight
 * @property string $severity
 * @property string $kind
 * @property int $priority
 * @property bool $is_active
 * @property int|null $cooldown_seconds
 * @property string|null $created_by
 */
final class AntispamRuleModel extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'antispam_rules';

    protected $fillable = [
        'bot_id',
        'name',
        'group_id',
        'type',
        'config',
        'score_weight',
        'severity',
        'kind',
        'priority',
        'is_active',
        'cooldown_seconds',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'score_weight' => 'integer',
            'priority' => 'integer',
            'is_active' => 'boolean',
            'cooldown_seconds' => 'integer',
        ];
    }


    protected static function newFactory(): Factory
    {
        return \BAGArt\TelegramBotAntispam\Database\Factories\AntispamRuleModelFactory::new();
    }
}
