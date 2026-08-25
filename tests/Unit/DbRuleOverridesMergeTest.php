<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Tests\Unit;

use BAGArt\TelegramBotAntispam\Engine\DbRuleOverrides;
use PHPUnit\Framework\TestCase;

final class DbRuleOverridesMergeTest extends TestCase
{
    public function testEmptyDbSectionsKeepChatSettings(): void
    {
        $chatSettings = [
            'strictness' => 'strict',
            'disabled_rules' => ['advertising.regex' => false],
        ];

        $db = [
            'rule_scores' => [],
            'rule_severities' => [],
            'rule_kinds' => [],
            'rule_params' => [],
            'rule_cooldowns' => [],
            'disabled_rules' => [],
            'db_rules' => [],
        ];

        $merged = DbRuleOverrides::mergeInto($chatSettings, $db);

        self::assertSame('strict', $merged['strictness']);
        self::assertSame(['advertising.regex' => false], $merged['disabled_rules']);
    }

    public function testDbWinsPerRuleIdButKeepsUnrelatedChatEntries(): void
    {
        $chatSettings = [
            'disabled_rules' => ['advertising.regex' => false],
            'rule_scores' => ['flood.rate' => 11],
        ];

        $db = [
            'disabled_rules' => ['flood.rate.burst' => false],
            'rule_scores' => ['advertising.regex' => 15],
        ];

        $merged = DbRuleOverrides::mergeInto($chatSettings, $db);

        self::assertFalse($merged['disabled_rules']['advertising.regex']);
        self::assertFalse($merged['disabled_rules']['flood.rate.burst']);
        self::assertSame(11, $merged['rule_scores']['flood.rate']);
        self::assertSame(15, $merged['rule_scores']['advertising.regex']);
    }
}
