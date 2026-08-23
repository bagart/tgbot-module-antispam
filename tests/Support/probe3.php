<?php

require dirname(__DIR__, 5).'/vendor/autoload.php';

use BAGArt\TelegramBotAntispam\Counters\CounterBatch;
use BAGArt\TelegramBotAntispam\Counters\RedisBatchCounter;

$counter = new RedisBatchCounter(connectionResolver: fn (string $n): object => new stdClass);

$call = Closure::bind(function (array $result): \BAGArt\TelegramBotAntispam\Domain\CounterSnapshot {
    return $this->toSnapshot($result, new CounterBatch(botId: 'b', chatId: 1, userId: 2, eventId: 'x'));
}, $counter, RedisBatchCounter::class);

$snapshot = $call(['messages:5' => '1', 'messages:30' => '2', 'recent', '[]']);
echo 'direct toSnapshot: messages5s='.$snapshot->messages5s.' messages30s='.$snapshot->messages30s.PHP_EOL;
var_export($snapshot);
echo PHP_EOL;
