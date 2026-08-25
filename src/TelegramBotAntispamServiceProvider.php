<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam;

use BAGArt\AsyncKernel\Wrappers\ASKCacheWrapper;
use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;
use BAGArt\TelegramBot\Contracts\Modules\ModuleSettingsContract;
use BAGArt\TelegramBotAntispam\Counters\Counter;
use BAGArt\TelegramBotAntispam\Counters\MemoryBatchCounter;
use BAGArt\TelegramBotAntispam\Counters\ObservationCollector;
use BAGArt\TelegramBotAntispam\Counters\RedisBatchCounter;
use BAGArt\TelegramBotAntispam\Engine\AntispamEvaluator;
use BAGArt\TelegramBotAntispam\Engine\PolicyCompiler;
use BAGArt\TelegramBotAntispam\Engine\PolicyEvaluator;
use BAGArt\TelegramBotAntispam\Engine\RuleEngine;
use BAGArt\TelegramBotAntispam\Engine\VerdictAggregator;
use BAGArt\TelegramBotAntispam\Enforcement\ActionExecutor;
use BAGArt\TelegramBotAntispam\Risk\RiskContextBuilder;
use BAGArt\TelegramBotAntispam\Rules\RuleCooldown;
use BAGArt\TelegramBotAntispam\Rules\RuleRegistry;
use BAGArt\TelegramBotAntispam\Strike\EscalationPolicy;
use BAGArt\TelegramBotAntispam\Strike\StrikeManager;
use BAGArt\TelegramBotAntispam\UserList\UserListManager;
use BAGArt\TelegramBotAntispam\Violation\ViolationRecorder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class TelegramBotAntispamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/antispam.php', 'antispam');

        // Composer-installed module discovery (config/telegram.php contract)
        $providers = (array) Config::get('telegram.modules_providers', []);
        Config::set('telegram.modules_providers', array_values(array_unique(array_merge(
            $providers,
            [AntispamModule::class],
        ))));

        // Default seed data (config/telegram.php contract): the host
        // DatabaseSeeder consumes this list without knowing the module.
        $seeders = (array) Config::get('telegram.modules_seeders', []);
        Config::set('telegram.modules_seeders', array_values(array_unique(array_merge(
            $seeders,
            [Database\Seeders\AntispamDefaultsSeeder::class],
        ))));

        $this->app->singleton(Counter::class, fn ($app): Counter => (string) Config::get('antispam.counter_driver') === 'memory'
            ? $this->makeMemoryCounter()
            : $this->makeRedisCounter());

        $this->app->singleton(RuleRegistry::class);
        $this->app->singleton(ObservationCollector::class);

        $this->app->singleton(RuleEngine::class, fn ($app): RuleEngine => new RuleEngine(
            iterator_to_array($app->make(RuleRegistry::class)),
            self::detectionSources($app),
        ));
        $this->app->singleton(VerdictAggregator::class);
        $this->app->singleton(PolicyEvaluator::class);
        $this->app->singleton(AntispamEvaluator::class);

        $this->app->singleton(PolicyCompiler::class, fn ($app): PolicyCompiler => new PolicyCompiler(
            registry: $app->make(RuleRegistry::class),
            cache: $app->make(ASKCacheWrapper::class),
            ttlSeconds: (int) Config::get('antispam.cache_ttl_seconds', 300),
        ));

        $this->app->singleton(RiskContextBuilder::class, fn ($app): RiskContextBuilder => new RiskContextBuilder(
            cache: $app->make(ASKCacheWrapper::class),
            ttlSeconds: (int) Config::get('antispam.risk_cache_ttl_seconds', 60),
        ));

        $this->app->singleton(UserListManager::class, fn ($app): UserListManager => new UserListManager(
            cache: $app->make(ASKCacheWrapper::class),
            ttlSeconds: (int) Config::get('antispam.cache_ttl_seconds', 300),
        ));

        $this->app->singleton(ViolationRecorder::class);

        $this->app->singleton(EscalationPolicy::class, fn (): EscalationPolicy => new EscalationPolicy(
            decayWindowDays: (int) Config::get('antispam.strike_decay_days', 7),
        ));

        $this->app->singleton(StrikeManager::class, fn ($app): StrikeManager => new StrikeManager(
            escalationPolicy: $app->make(EscalationPolicy::class),
            cache: $app->make(ASKCacheWrapper::class),
        ));

        $this->app->singleton(ActionExecutor::class, fn ($app): ActionExecutor => new ActionExecutor(
            sender: $app->make(\BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract::class),
            recorder: $app->make(ViolationRecorder::class),
            logger: $app->make(ASKLogWrapper::class),
        ));

        $this->app->singleton(RuleCooldown::class, fn ($app): RuleCooldown => new RuleCooldown(
            cache: $app->make(ASKCacheWrapper::class),
        ));

        $this->app->singleton(\BAGArt\TelegramBotAntispam\Engine\DbRuleOverrides::class, fn ($app) => new \BAGArt\TelegramBotAntispam\Engine\DbRuleOverrides(
            cache: $app->make(ASKCacheWrapper::class),
            ttlSeconds: (int) Config::get('antispam.cache_ttl_seconds', 300),
        ));

        $this->app->singleton(\BAGArt\TelegramBotAntispam\Appeals\AppealManager::class);

        $this->app->singleton(\BAGArt\TelegramBotAntispam\Captcha\CaptchaStore::class, fn ($app) => new \BAGArt\TelegramBotAntispam\Captcha\CaptchaStore(
            cache: $app->make(ASKCacheWrapper::class),
        ));

        $this->app->singleton(\BAGArt\TelegramBotAntispam\Captcha\CaptchaService::class, fn ($app) => new \BAGArt\TelegramBotAntispam\Captcha\CaptchaService(
            store: $app->make(\BAGArt\TelegramBotAntispam\Captcha\CaptchaStore::class),
            lists: $app->make(UserListManager::class),
            sender: $app->make(\BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract::class),
            settings: $app->make(ModuleSettingsContract::class),
            logger: $app->make(ASKLogWrapper::class),
            excludeUserIds: array_map('intval', (array) Config::get('antispam.exclude_user_ids', [])),
        ));

        $this->app->singleton(\BAGArt\TelegramBotAntispam\Moderation\AntispamModerationService::class);

        $this->app->singleton(\BAGArt\TelegramBotAntispam\DryRun\DryRunExecutor::class);

        $this->app->singleton(\BAGArt\TelegramBotAntispam\Replay\ReplayEvaluator::class);

        $this->app->singleton(AntispamPipeline::class, function ($app): AntispamPipeline {
            /** @var array<string, mixed> $defaults */
            $defaults = (array) Config::get('antispam.policy_defaults', []);

            return new AntispamPipeline(
                normalizer: new \BAGArt\TelegramBotAntispam\Processors\MessageNormalizer(),
                collector: $app->make(ObservationCollector::class),
                counter: $app->make(Counter::class),
                compiler: $app->make(PolicyCompiler::class),
                evaluator: $app->make(AntispamEvaluator::class),
                riskBuilder: $app->make(RiskContextBuilder::class),
                lists: $app->make(UserListManager::class),
                recorder: $app->make(ViolationRecorder::class),
                strikes: $app->make(StrikeManager::class),
                executor: $app->make(ActionExecutor::class),
                cooldown: $app->make(RuleCooldown::class),
                settings: $app->make(ModuleSettingsContract::class),
                logger: $app->make(ASKLogWrapper::class),
                dbRuleOverrides: $app->make(\BAGArt\TelegramBotAntispam\Engine\DbRuleOverrides::class),
                captcha: $app->make(\BAGArt\TelegramBotAntispam\Captcha\CaptchaService::class),
                enablement: $app->bound(\BAGArt\TelegramBot\Contracts\Modules\ModuleEnablementContract::class)
                    ? $app->make(\BAGArt\TelegramBot\Contracts\Modules\ModuleEnablementContract::class)
                    : null,
                excludeUserIds: array_map('intval', (array) Config::get('antispam.exclude_user_ids', [])),
            );
        });
    }

    public function boot(): void
    {
        $this->commands([
            \BAGArt\TelegramBotAntispam\Commands\BlocklistSyncCommand::class,
            \BAGArt\TelegramBotAntispam\Commands\ValidateDatasetCommand::class,
        ]);

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
    }

    private function makeRedisCounter(): RedisBatchCounter
    {
        return new RedisBatchCounter(
            connection: (string) Config::get('antispam.redis.connection', 'default'),
            graceSeconds: (int) Config::get('antispam.redis.grace_seconds', 10),
            fingerprintCap: (int) Config::get('antispam.redis.fingerprint_cap', 1000),
            fingerprintWindow: (int) Config::get('antispam.redis.fingerprint_window', 300),
        );
    }

    private function makeMemoryCounter(): MemoryBatchCounter
    {
        return new MemoryBatchCounter(
            graceSeconds: (int) Config::get('antispam.redis.grace_seconds', 10),
            fingerprintCap: (int) Config::get('antispam.redis.fingerprint_cap', 1000),
            fingerprintWindow: (int) Config::get('antispam.redis.fingerprint_window', 300),
        );
    }

    /**
     * Detection sources beyond built-in rules. The honeypot is always on
     * (settings-driven); the AI classifier registers only when enabled —
     * the core engine stays AI-free.
     *
     * @return list<\BAGArt\TelegramBotAntispam\Rules\DetectionSource>
     */
    private static function detectionSources($app): array
    {
        $sources = [new \BAGArt\TelegramBotAntispam\Risk\HoneypotDetector()];

        if ((bool) Config::get('antispam.ai.enabled', false)) {
            $sources[] = new \BAGArt\TelegramBotAntispam\Ai\AiSpamDetector(
                cache: $app->make(ASKCacheWrapper::class),
                logger: $app->make(ASKLogWrapper::class),
            );
        }

        return $sources;
    }
}
