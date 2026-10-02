<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam;

use BAGArt\TelegramBotMenu\Contracts\TgPermissionResolverContract;
use BAGArt\TelegramBotMenu\Manifest\EffectiveRole;
use BAGArt\TelegramBotMenu\Support\TgUiContext;

/**
 * RBAC resolver for antispam module settings (§8.10/D58 proof-of-concept).
 *
 * antispam.configure — Admin and above can modify antispam policy.
 * antispam.view     — Member and above can view antispam status (read-only).
 */
final class AntispamPermissionResolver implements TgPermissionResolverContract
{
    public const string CONFIGURE = 'antispam.configure';

    public const string VIEW = 'antispam.view';

    public static function permissions(): array
    {
        return [self::CONFIGURE, self::VIEW];
    }

    public function resolve(array $permissionIds, TgUiContext $context): array
    {
        $verdicts = [];

        foreach ($permissionIds as $id) {
            $verdicts[$id] = match ($id) {
                self::CONFIGURE => $context->role->atLeast(EffectiveRole::Admin),
                self::VIEW => $context->role->atLeast(EffectiveRole::Member),
                default => false,
            };
        }

        return $verdicts;
    }

    public function revision(): string
    {
        return '1';
    }
}
