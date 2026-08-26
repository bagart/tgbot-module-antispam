<?php

/**
 * Antispam pipeline rate benchmark (todo.antispam.md final phase).
 *
 * Drives AntispamPipeline::handle() directly at a target messages-per-minute
 * with live Redis counters and reports per-message latency percentiles plus
 * stage timings (ANTISPAM_INSTRUMENTATION). This isolates the anti-spam cost
 * per message from HTTP/framework bootstrap noise.
 *
 * Usage:
 *   DB_CONNECTION=sqlite DB_DATABASE=... REDIS_HOST=127.0.0.1 \
 *   ANTISPAM_INSTRUMENTATION=true php tools/bench-pipeline.php \
 *     --bot=9001 --chat=-1009001 --rate=1000 --count=1000
 */

declare(strict_types=1);

use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;
use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\MessageTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\UserTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\Enum\ChatPropTypeEnum;
use BAGArt\TelegramBotAntispam\AntispamPipeline;

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$opts = getopt('', ['bot:', 'chat:', 'rate:', 'count:', 'user:']);
$botId = (string) ($opts['bot'] ?? '9001');
$chatId = (int) ($opts['chat'] ?? -1009001);
$ratePerMinute = max(1, (int) ($opts['rate'] ?? 1000));
$count = max(10, (int) ($opts['count'] ?? 1000));
$senderUserId = (int) ($opts['user'] ?? 424242);

/** Captures antispam.stage entries for the report. */
$stages = new Illuminate\Support\Collection();
app()->instance(ASKLogWrapper::class, new ASKLogWrapper(new class ($stages) implements \Psr\Log\LoggerInterface {
    public function __construct(private readonly \Illuminate\Support\Collection $sink)
    {
    }

    public function emergency(string|Stringable $m, array $c = []): void
    {
    }

    public function alert(string|Stringable $m, array $c = []): void
    {
    }

    public function critical(string|Stringable $m, array $c = []): void
    {
    }

    public function error(string|Stringable $m, array $c = []): void
    {
    }

    public function warning(string|Stringable $m, array $c = []): void
    {
        $this->log('warning', $m, $c);
    }

    public function notice(string|Stringable $m, array $c = []): void
    {
    }

    public function info(string|Stringable $m, array $c = []): void
    {
    }

    public function debug(string|Stringable $m, array $c = []): void
    {
        $this->log('debug', $m, $c);
    }

    public function log($level, string|Stringable $m, array $c = []): void
    {
        if (str_contains((string) $m, 'antispam.stage')) {
            $this->sink->push(['stage' => (string) ($c['stage'] ?? '?'), 'ms' => (float) ($c['duration_ms'] ?? 0)]);
        }
    }
}, ASKLogWrapper::LEVEL_DEBUG));

$botConfig = new TgBotConfig(token: $botId.':loadtoken', botId: $botId);
$pipeline = app(AntispamPipeline::class);

$intervalMicro = (int) (60_000_000 / $ratePerMinute);
$latencies = [];
$p95s = ['observe' => [], 'detect' => [], 'violation' => []];

echo sprintf(
    "Pipeline bench: bot=%s chat=%d rate=%d/min count=%d\n",
    $botId,
    $chatId,
    $ratePerMinute,
    $count,
);

for ($seq = 1; $seq <= $count; ++$seq) {
    $tickStart = microtime(true);

    // every 10th message is hard advertising spam, rest are clean traffic
    $text = $seq % 10 === 0
        ? 'join t.me/spam_channel now'
        : 'hello everyone, nice weather today #'.$seq;

    $message = new MessageTypeDTO(
        messageId: $seq,
        date: time(),
        chat: new ChatTypeDTO(id: (string) $chatId, type: ChatPropTypeEnum::SUPERGROUP),
        from: new UserTypeDTO(id: (string) $senderUserId, isBot: false, firstName: 'Load'),
        text: $text,
    );

    $pipeline->handle($message, $botConfig);
    $latencies[] = (microtime(true) - $tickStart) * 1000;

    // pace to the target rate
    $nextTick = $tickStart + $intervalMicro / 1_000_000;
    if (($sleep = $nextTick - microtime(true)) > 0) {
        usleep((int) ($sleep * 1_000_000));
    }
}

foreach ($stages as $entry) {
    $p95s[$entry['stage']][] = $entry['ms'];
}

sort($latencies);
$pct = static function (array $values, float $p): float {
    if ($values === []) {
        return 0.0;
    }
    sort($values);

    return $values[min(count($values) - 1, (int) floor(count($values) * $p))];
};

echo sprintf(
    "messages=%d\nhandle_ms: p50=%.2f p95=%.2f p99=%.2f max=%.2f avg=%.2f\n",
    count($latencies),
    $pct($latencies, 0.50),
    $pct($latencies, 0.95),
    $pct($latencies, 0.99),
    $latencies[count($latencies) - 1],
    array_sum($latencies) / count($latencies),
);

foreach (['observe', 'detect', 'violation'] as $stage) {
    $values = $p95s[$stage];
    echo sprintf(
        "stage %s: n=%d p50=%.2fms p95=%.2fms max=%.2fms\n",
        $stage,
        count($values),
        $pct($values, 0.50),
        $pct($values, 0.95),
        $values === [] ? 0.0 : max($values),
    );
}
