<?php

/**
 * Antispam webhook load driver (todo.antispam.md final phase).
 *
 * Drives POST /tg/tg_webhook/{bot_id} with valid derived secrets at a target
 * rate and reports latency percentiles for the webhook endpoint. Requests run
 * concurrently (curl_multi) so the target rate is actually reachable.
 *
 * Usage:
 *   php tools/load-webhook.php --url=http://127.0.0.1:8000 \
 *        --bot=my_bot --chat=-1001234 --rate=1000 --duration=60
 *
 * --rate is messages per minute (tracker target: 1000 msg/min ≈ 17 rps).
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$opts = getopt('', ['url:', 'bot:', 'chat:', 'rate:', 'duration:', 'user:']);
$url = (string) ($opts['url'] ?? 'http://127.0.0.1:8000');
$botId = (string) ($opts['bot'] ?? throw new InvalidArgumentException('--bot is required'));
$chatId = (int) ($opts['chat'] ?? -1000000000);
$ratePerMinute = max(1, (int) ($opts['rate'] ?? 1000));
$durationSeconds = max(5, (int) ($opts['duration'] ?? 60));
$senderUserId = (int) ($opts['user'] ?? 424242);

$token = DB::table('tg_bots')->where('bot_id', $botId)->value('token')
    ?? throw new RuntimeException("Bot [{$botId}] not found.");

// The webhook secret is derived from the bot token: "{numericId}:{sha256(tokenPart)}"
[$tokenBotId, $tokenPart] = explode(':', $token, 2);
if (! is_numeric($tokenBotId) || $tokenPart === '') {
    throw new RuntimeException('Bot token must look like "123456:part" to derive the webhook secret.');
}
$secret = $tokenBotId.':'.hash('sha256', $tokenPart);

// Concurrency window: enough in-flight requests to sustain the target rate
// even when single-request latency is high (window = rate × latency budget).
$rps = $ratePerMinute / 60;
$sendInterval = 1.0 / $rps;
$maxInFlight = max(1, (int) ceil($rps * 3));
$deadline = microtime(true) + $durationSeconds;
$endpoint = rtrim($url, '/').'/tg/tg_webhook/'.$botId;

$latencies = [];
$statuses = [];
$seq = 0;

echo sprintf(
    "Driving %s at %d msg/min (%.1f rps, window<=%d) for %ds\n",
    $endpoint,
    $ratePerMinute,
    $rps,
    $maxInFlight,
    $durationSeconds,
);

function payload(int $seq, int $chatId, int $senderUserId): string
{
    return json_encode([
        'update_id' => 9_000_000 + $seq,
        'message' => [
            'message_id' => $seq,
            'date' => time(),
            'chat' => ['id' => $chatId, 'type' => 'supergroup', 'title' => 'load'],
            'from' => [
                'id' => $senderUserId,
                'is_bot' => false,
                'first_name' => 'Load',
                'username' => 'load_tester',
            ],
            // rotate between clean text and hard advertising patterns
            'text' => $seq % 10 === 0
                ? 'join t.me/spam_channel now'
                : 'hello everyone, nice weather today #'.$seq,
        ],
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Open-loop driver: one shared curl_multi handle, requests spawned on a fixed
 * schedule (rate independent of completion), bounded in-flight window.
 */
$mh = curl_multi_init();
/** @var array<int, resource> $inflight curl handle → start timestamp */
$inflight = [];
$nextSend = microtime(true);

while (microtime(true) < $deadline || $inflight !== []) {
    $now = microtime(true);

    // Spawn due requests while under the window cap.
    while ($now >= $nextSend && count($inflight) < $maxInFlight) {
        if ($now < $deadline) {
            ++$seq;
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => payload($seq, $chatId, $senderUserId),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Telegram-Bot-Api-Secret-Token: '.$secret,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => 10000,
            ]);
            curl_multi_add_handle($mh, $ch);
            $inflight[(int) $ch] = $now;
            $nextSend += $sendInterval;
        } else {
            break;
        }
    }

    curl_multi_exec($mh, $active);

    // Collect completions.
    while (($info = curl_multi_info_read($mh)) !== false) {
        /** @var \CurlHandle $handle */
        $handle = $info['handle'];
        $key = (int) $handle;
        $startedAt = $inflight[$key] ?? $now;
        unset($inflight[$key]);

        $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $statuses[$code] = ($statuses[$code] ?? 0) + 1;
        $latencies[] = ($now - $startedAt) * 1000;

        curl_multi_remove_handle($mh, $handle);
        curl_close($handle);
    }

    if ($active) {
        curl_multi_select($mh, 0.02);
    } else {
        usleep(2000);
    }
}

curl_multi_close($mh);

sort($latencies);
$count = count($latencies);
if ($count === 0) {
    exit("No requests completed.\n");
}

$pct = static fn (float $p): float => $latencies[min($count - 1, (int) floor($count * $p))];
echo sprintf(
    "requests=%d statuses=%s\np50=%.1fms p95=%.1fms p99=%.1fms max=%.1fms\n",
    $count,
    json_encode($statuses),
    $pct(0.50),
    $pct(0.95),
    $pct(0.99),
    $latencies[$count - 1],
);
