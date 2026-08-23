<?php

require dirname(__DIR__, 5).'/vendor/autoload.php';

use BAGArt\TelegramBotAntispam\Counters\CounterBatch;
use BAGArt\TelegramBotAntispam\Counters\RedisBatchCounter;

$spy = new class () {
    public int $evalCalls = 0;

    public function eval(string $script, array $args = [], int $numKeys = 0): array
    {
        echo 'EVAL on obj id='.spl_object_id($this).' calls_before='.$this->evalCalls.PHP_EOL;
        $this->evalCalls++;

        return ['messages:5' => '1', 'recent', '[]'];
    }
};
echo 'spy id='.spl_object_id($spy).PHP_EOL;

$counter = new RedisBatchCounter(connectionResolver: fn (string $n): object => $spy);
$prop = (new ReflectionClass($counter))->getProperty('connectionResolver');
$closure = $prop->getValue($counter);
echo 'closure binds this? '.json_encode((new ReflectionFunction($closure))->getClosureThis() === null).PHP_EOL;

$snapshot = $counter->record(new CounterBatch(botId: 'b', chatId: 1, userId: 2, eventId: 'm1', messages: 1));
echo 'evalCalls='.$spy->evalCalls.' snapshot.messages5s='.$snapshot->messages5s.PHP_EOL;
