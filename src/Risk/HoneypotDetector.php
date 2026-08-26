<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Risk;

use BAGArt\TelegramBotAntispam\Domain\MessageData;
use BAGArt\TelegramBotAntispam\Domain\RuleRequirements;

/**
 * Honeypot detection source (todo.antispam.md P3.8): messages containing a
 * configured trigger word/link are an instant hard signal. Words live in the
 * per-chat module settings: {"honeypot": {"words": ["free-crypto", ...]}}.
 * Absent/empty configuration → never triggers.
 */
final class HoneypotDetector implements \BAGArt\TelegramBotAntispam\Rules\DetectionSource
{
    public const string SOURCE_ID = 'honeypot.trigger';
    public const string SETTINGS_KEY = 'honeypot';

    public function id(): string
    {
        return self::SOURCE_ID;
    }

    public function group(): string
    {
        return 'advertising';
    }

    public function requirements(): RuleRequirements
    {
        return new RuleRequirements(requiresText: true);
    }

    public function check(\BAGArt\TelegramBotAntispam\Domain\AntispamMessageContext $context): ?\BAGArt\TelegramBotAntispam\Domain\AntiSpamDetection
    {
        $matched = self::firstMatch(
            self::wordsOf((array) $context->settings),
            $context->message,
        );
        if ($matched === null) {
            return null;
        }

        return new \BAGArt\TelegramBotAntispam\Domain\AntiSpamDetection(
            ruleId: self::SOURCE_ID,
            score: 100,
            severity: \BAGArt\TelegramBotAntispam\Domain\DetectionSeverity::High,
            kind: \BAGArt\TelegramBotAntispam\Domain\DetectionKind::Hard,
            group: 'advertising',
            reason: "Honeypot trigger matched: {$matched}",
            metadata: ['trigger' => $matched],
        );
    }

    /**
     * @param  list<string>  $words
     */
    public static function firstMatch(array $words, MessageData $message): ?string
    {
        $text = $message->effectiveText();
        if ($text === null || $words === []) {
            return null;
        }

        foreach ($words as $word) {
            $needle = mb_strtolower(trim((string) $word));
            if ($needle !== '' && str_contains(mb_strtolower($text), $needle)) {
                return (string) $word;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    public static function wordsOf(array $settings): array
    {
        $config = (array) ($settings[self::SETTINGS_KEY] ?? []);
        $words = (array) ($config['words'] ?? []);

        return array_values(array_filter(array_map('strval', $words)));
    }
}
