<?php

/**
 * Control driver: hits a trivial endpoint (/up) at the same rate so webhook
 * latencies can be attributed — control = framework+server floor,
 * webhook = floor + antispam module work.
 */

declare(strict_types=1);

$opts = getopt('', ['url:', 'rate:', 'duration:']);
$url = rtrim((string) ($opts['url'] ?? 'http://127.0.0.1:8000'), '/');
$ratePerMinute = max(1, (int) ($opts['rate'] ?? 300));
$durationSeconds = max(5, (int) ($opts['duration'] ?? 15));

$rps = $ratePerMinute / 60;
$batchSize = max(1, (int) ceil($rps * 1.5));
$intervalMicro = (int) (1_000_000 / $rps);
$deadline = microtime(true) + $durationSeconds;

$latencies = [];
$statuses = [];

echo sprintf("CONTROL GET %s/up at %d msg/min (batch=%d) for %ds\n", $url, $ratePerMinute, $batchSize, $durationSeconds);

while (microtime(true) < $deadline) {
    $tickStart = microtime(true);

    $handles = [];
    for ($i = 0; $i < $batchSize && microtime(true) < $deadline; ++$i) {
        $ch = curl_init($url.'/up');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 5000]);
        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $ch);
        $handles[] = $mh;
    }

    foreach ($handles as $mh) {
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh, 0.05);
            }
        } while ($active && $status === CURLM_OK);

        $info = curl_multi_info_read($mh);
        $handle = $info['handle'] ?? null;
        $code = $handle !== null ? (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE) : 0;
        $statuses[$code] = ($statuses[$code] ?? 0) + 1;
        $latencies[] = (microtime(true) - $tickStart) * 1000;
        curl_multi_close($mh);
    }

    $nextTick = $tickStart + $intervalMicro / 1_000_000;
    if (microtime(true) < $nextTick) {
        usleep((int) (($nextTick - microtime(true)) * 1_000_000));
    }
}

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
