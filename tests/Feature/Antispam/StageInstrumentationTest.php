<?php

declare(strict_types=1);

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;
use BAGArt\TelegramBotManagement\Models\TgModuleEnablement;

require_once __DIR__.'/AntispamHelpers.php';

/**
 * Perf-budget stage instrumentation: when enabled, the pipeline emits
 * "antispam.stage" debug entries with per-stage durations; when disabled,
 * it stays silent.
 */
it('emits stage timings when instrumentation is enabled and stays silent otherwise', function () {
    config(['antispam.counter_driver' => 'memory']);

    \BAGArt\TelegramBotManagement\Models\TgBot::create(['bot_id' => 'test_bot', 'token' => 't:token']);
    TgModuleEnablement::factory()->forChat('test_bot', 100)->enabled(true)
        ->create(['module_id' => 'antispam']);

    $entries = new Illuminate\Support\Collection();
    $logger = new ASKLogWrapper(new class ($entries) implements \Psr\Log\LoggerInterface {
        public function __construct(private readonly \Illuminate\Support\Collection $sink)
        {
        }

        public function emergency(string|Stringable $m, array $c = []): void
        {
            $this->log('emergency', $m);
        }

        public function alert(string|Stringable $m, array $c = []): void
        {
            $this->log('alert', $m);
        }

        public function critical(string|Stringable $m, array $c = []): void
        {
            $this->log('critical', $m);
        }

        public function error(string|Stringable $m, array $c = []): void
        {
            $this->log('error', $m);
        }

        public function warning(string|Stringable $m, array $c = []): void
        {
            $this->log('warning', $m);
        }

        public function notice(string|Stringable $m, array $c = []): void
        {
            $this->log('notice', $m);
        }

        public function info(string|Stringable $m, array $c = []): void
        {
            $this->log('info', $m);
        }

        public function debug(string|Stringable $m, array $c = []): void
        {
            $this->log('debug', $m, $c);
        }

        public function log($level, string|Stringable $m, array $c = []): void
        {
            $this->sink->push(['level' => $level, 'message' => (string) $m, 'context' => $c]);
        }
    }, ASKLogWrapper::LEVEL_DEBUG);

    config(['antispam.instrumentation' => true]);
    app()->instance(ASKLogWrapper::class, $logger);
    // bind the spy sender before the pipeline materializes
    app()->instance(TgSenderContract::class, senderSpy());

    $pipeline = app(\BAGArt\TelegramBotAntispam\AntispamPipeline::class);
    $botConfig = new TgBotConfig(token: 'x:token', botId: 'test_bot');

    // clean path → observe + detect stages
    $pipeline->handle(antispamMessage(100, 42, 'nice weather today'), $botConfig);
    // spam path → observe + detect + violation stages
    $pipeline->handle(antispamMessage(100, 43, 'join t.me/spam_channel now', 11), $botConfig);

    fwrite(STDERR, 'ENTRIES: '.$entries->toJson()."
");
    $stages = $entries
        ->filter(fn (array $e): bool => str_contains($e['message'], 'antispam.stage'))
        ->pluck('context.stage')
        ->values()->all();

    expect($stages)->toContain('observe')
        ->and($stages)->toContain('detect')
        ->and($stages)->toContain('violation');

    // Disabled: no stage entries at all.
    $entries = new Illuminate\Support\Collection();
    config(['antispam.instrumentation' => false]);
    $pipeline->handle(antispamMessage(100, 44, 'join t.me/spam_channel now', 12), $botConfig);

    $stageEntries = $entries->filter(fn (array $e): bool => str_contains($e['message'], 'antispam.stage'));
    expect($stageEntries->all())->toBe([]);
});
