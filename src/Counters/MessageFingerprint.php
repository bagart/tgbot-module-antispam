<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Counters;

use Normalizer;

/**
 * Message normalization for all Repeated*Rule fingerprints: case folding →
 * Unicode NFC → trim → whitespace collapse → punctuation strip → emoji
 * variation-selector strip. "Hello/hello/HELLO/Hello!" collapse to one fingerprint.
 */
final class MessageFingerprint
{
    public function of(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        $normalized = normalizer_normalize($text, Normalizer::FORM_C) ?? $text;
        $normalized = mb_strtolower($normalized);
        // Emoji variation selectors and zero-width joiners create phantom variants
        $normalized = preg_replace('/[\x{FE0F}\x{200D}\x{FE0E}]/u', '', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', (string) $normalized);
        $normalized = preg_replace('/[[:punct:]]/u', '', (string) $normalized);
        $normalized = trim((string) $normalized);

        return $normalized === '' ? null : hash('sha256', $normalized);
    }
}
