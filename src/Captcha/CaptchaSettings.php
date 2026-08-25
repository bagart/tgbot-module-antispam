<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Captcha;

/**
 * CAPTCHA feature settings parsed from module_settings['captcha'].
 * Absent key = disabled — the feature is opt-in per chat.
 *
 * module_settings['captcha'] = {
 *   enabled: bool,
 *   on_fail: 'ban'|'kick',
 *   ttl_seconds: int (30..3600),
 *   whitelist_seconds: int (60..86400)
 * }
 */
final readonly class CaptchaSettings
{
    public const string FAIL_BAN = 'ban';
    public const string FAIL_KICK = 'kick';

    public function __construct(
        public bool $enabled = false,
        public string $onFail = self::FAIL_BAN,
        public int $ttlSeconds = 300,
        public int $whitelistSeconds = 3600,
    ) {
    }

    /** @param  array<string, mixed>  $moduleSettings */
    public static function fromSettings(array $moduleSettings): self
    {
        $captcha = (array) ($moduleSettings['captcha'] ?? []);
        if ($captcha === []) {
            return new self();
        }

        $onFail = (string) ($captcha['on_fail'] ?? self::FAIL_BAN);

        return new self(
            enabled: (bool) ($captcha['enabled'] ?? false),
            onFail: in_array($onFail, [self::FAIL_BAN, self::FAIL_KICK], true) ? $onFail : self::FAIL_BAN,
            ttlSeconds: max(30, min(3600, (int) ($captcha['ttl_seconds'] ?? 300))),
            whitelistSeconds: max(60, min(86400, (int) ($captcha['whitelist_seconds'] ?? 3600))),
        );
    }
}
