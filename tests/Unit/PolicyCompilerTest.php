<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Tests\Unit;

use BAGArt\TelegramBotAntispam\Engine\PolicyCompiler;
use BAGArt\TelegramBotAntispam\Rules\RuleRegistry;
use BAGArt\TelegramBotAntispam\Tests\Support\ArrayCache;
use PHPUnit\Framework\TestCase;

final class PolicyCompilerTest extends TestCase
{
    public function test_default_thresholds_for_normal_strictness(): void
    {
        $plan = $this->compiler()->compile('bot', 1, ['strictness' => 'normal']);

        self::assertSame(['warn' => 40, 'restrict' => 80, 'ban' => 150], $plan->thresholds());
        self::assertSame(200, $plan->globalCap);
    }

    public function test_strict_strictness_lowers_thresholds(): void
    {
        $plan = $this->compiler()->compile('bot', 1, ['strictness' => 'strict']);

        self::assertSame(['warn' => 24, 'restrict' => 48, 'ban' => 90], $plan->thresholds());
    }

    public function test_explicit_thresholds_override_strictness(): void
    {
        $plan = $this->compiler()->compile('bot', 1, [
            'strictness' => 'strict',
            'thresholds' => ['warn' => 50, 'restrict' => 90, 'ban' => 160],
        ]);

        self::assertSame(['warn' => 50, 'restrict' => 90, 'ban' => 160], $plan->thresholds());
    }

    public function test_group_caps_and_rule_overrides(): void
    {
        $plan = $this->compiler()->compile('bot', 1, [
            'group_caps' => ['advertising' => 30],
            'rule_scores' => ['flood.rate.burst' => 99],
            'disabled_rules' => ['advertising.mention_flood'],
        ]);

        $caps = array_column($plan->groupCaps, 'cap', 'id');
        self::assertSame(30, $caps['advertising']);
        self::assertSame(99, $plan->scoreOf('flood.rate.burst', 10));
        self::assertFalse($plan->isEnabled('advertising.mention_flood'));
        self::assertTrue($plan->isEnabled('advertising.regex'));
    }

    public function test_compiled_plan_is_cached_per_ruleset_version(): void
    {
        $cache = new ArrayCache();
        $compiler = $this->compiler($cache);

        $first = $compiler->compile('bot', 1, ['strictness' => 'normal']);
        $second = $compiler->compile('bot', 1, ['strictness' => 'normal']);

        self::assertSame($first, $second);
        self::assertCount(1, $cache->items);
    }

    public function test_different_settings_give_different_versions(): void
    {
        $compiler = $this->compiler();

        $a = $compiler->compile('bot', 1, ['strictness' => 'strict']);
        $b = $compiler->compile('bot', 1, ['strictness' => 'relaxed']);

        self::assertNotSame($a->rulesetVersion, $b->rulesetVersion);
    }

    public function test_flood_windows_merge_with_defaults(): void
    {
        $plan = $this->compiler()->compile('bot', 1, ['flood_windows' => ['burst' => 9]]);

        self::assertSame(9, $plan->floodWindows['burst']);
        self::assertSame(15, $plan->floodWindows['short']);
    }

    private function compiler(?ArrayCache $cache = null): PolicyCompiler
    {
        return new PolicyCompiler(
            registry: new RuleRegistry(),
            cache: $cache ?? new ArrayCache(),
        );
    }
}
