<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Domain;

/**
 * Hard detection severity → minimum enforcement action. Hard ≠ automatic ban:
 * consequences are always explicit and versioned with the policy.
 */
final readonly class SeverityActionMap
{
    /**
     * @param  array<string, string>  $map  severity value → EnforcementAction value
     */
    public function __construct(
        public array $map = [
            'high' => 'restrict',
            'critical' => 'ban',
        ],
    ) {
    }

    /** Minimum action demanded by a hard detection of the given severity, if any. */
    public function minimumFor(DetectionSeverity $severity): ?EnforcementAction
    {
        $action = $this->map[$severity->value] ?? null;

        return $action === null ? null : EnforcementAction::fromName($action);
    }
}
