<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Events;

use BAGArt\TelegramBot\Contracts\Modules\ModuleAuditEventContract;

/**
 * Audit events dispatched by the anti-spam module. One class per module:
 * the operation vocabulary lives in constants so the dispatch sites stay
 * typo-free and greppable, and the module registers this single class
 * through TgModuleRegistrar::auditEvent().
 */
final class AntispamAuditEvent implements ModuleAuditEventContract
{
    public const string RULE_CREATED = 'antispam.rule.created';

    public const string RULE_CHANGED = 'antispam.rule.changed';

    public const string RULE_DELETED = 'antispam.rule.deleted';

    public const string SETTINGS_CHANGED = 'antispam.settings.changed';

    public const string VIOLATION_ENFORCED = 'antispam.violation.enforced';

    public const string VIOLATION_BULK_ENFORCED = 'antispam.violation.bulk.enforced';

    public const string APPEAL_DECIDED = 'antispam.appeal.decided';

    public const string USERLIST_ENTRY_ADDED = 'antispam.userlist.entry.added';

    public const string BLOCKLIST_SYNC_CHANGED = 'antispam.blocklist.sync.changed';

    public const string USERLIST_ENTRY_REMOVED = 'antispam.userlist.entry.removed';

    public const string APPEAL_SUBMITTED = 'antispam.appeal.submitted';

    public const string BLOCKLIST_SYNCED = 'antispam.blocklist.synced';

    public const string VIOLATION_RECORDED = 'antispam.violation.recorded';

    public const string ENFORCEMENT_APPLIED = 'antispam.enforcement.applied';

    /**
     * @param  array<string, mixed>|null  $oldState
     * @param  array<string, mixed>|null  $newState
     * @param  array<string, mixed>  $context  structured non-secret facts only — credentials/token values are forbidden
     */
    public function __construct(
        public readonly string $operation,
        public readonly string|int|null $botId = null,
        public readonly ?string $subjectType = null,
        public readonly string|int|null $subjectId = null,
        public readonly string|int|null $actorId = null,
        public readonly ?string $actorType = null,
        public readonly ?array $oldState = null,
        public readonly ?array $newState = null,
        public readonly array $context = [],
    ) {
    }

    public function moduleId(): string
    {
        return 'antispam';
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function botId(): ?string
    {
        return $this->botId !== null ? (string) $this->botId : null;
    }

    public function subjectType(): ?string
    {
        return $this->subjectType;
    }

    public function subjectId(): ?string
    {
        return $this->subjectId !== null ? (string) $this->subjectId : null;
    }

    public function actorId(): ?string
    {
        return $this->actorId !== null ? (string) $this->actorId : null;
    }

    public function actorType(): ?string
    {
        return $this->actorType;
    }

    public function oldState(): ?array
    {
        return $this->oldState;
    }

    public function newState(): ?array
    {
        return $this->newState;
    }

    public function context(): array
    {
        return $this->context;
    }
}
