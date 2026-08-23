<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Tests\Unit;

use BAGArt\TelegramBotAntispam\Counters\MessageFingerprint;
use PHPUnit\Framework\TestCase;

final class MessageFingerprintTest extends TestCase
{
    private MessageFingerprint $fingerprint;

    protected function setUp(): void
    {
        $this->fingerprint = new MessageFingerprint();
    }

    public function test_case_whitespace_and_punctuation_variants_collapse(): void
    {
        $base = $this->fingerprint->of('Hello world');
        self::assertNotNull($base);
        self::assertSame($base, $this->fingerprint->of('hello world'));
        self::assertSame($base, $this->fingerprint->of('HELLO   WORLD!'));
        self::assertSame($base, $this->fingerprint->of('  Hello, world.  '));
    }

    public function test_different_text_gives_different_fingerprint(): void
    {
        self::assertNotSame(
            $this->fingerprint->of('buy spam now'),
            $this->fingerprint->of('totally different'),
        );
    }

    public function test_empty_and_null_give_null(): void
    {
        self::assertNull($this->fingerprint->of(null));
        self::assertNull($this->fingerprint->of(''));
        self::assertNull($this->fingerprint->of('   !!!   '));
    }

    public function test_unicode_nfc_variants_collapse(): void
    {
        $composed = "caf\u{00E9}"; // é precomposed
        $decomposed = "cafe\u{0301}"; // e + combining acute

        self::assertSame(
            $this->fingerprint->of($composed),
            $this->fingerprint->of($decomposed),
        );
    }

    public function test_emoji_variation_selectors_collapse(): void
    {
        self::assertSame(
            $this->fingerprint->of("\u{2764}\u{FE0F} nice"),
            $this->fingerprint->of("\u{2764} nice"),
        );
    }
}
