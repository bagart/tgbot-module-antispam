<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Score cap per rule group ("advertising"/"flood"/…); NULL bot_id = platform default row.
 *
 * @property string $id
 * @property string $group_id
 * @property string $title
 * @property int $cap
 * @property string|null $bot_id
 */
final class AntispamRuleGroup extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'antispam_rule_groups';

    protected $fillable = [
        'group_id',
        'title',
        'cap',
        'bot_id',
    ];

    protected function casts(): array
    {
        return [
            'cap' => 'integer',
        ];
    }
}
