<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Counters;

use BAGArt\TelegramBotAntispam\Domain\CounterSnapshot;

/**
 * Batch Counter API: records ALL events of one message atomically and returns
 * the pre-computed snapshot. Implementations guarantee bounded key lifetime
 * (TTL = window + grace) and bounded fingerprint cardinality.
 */
interface Counter
{
    public function record(CounterBatch $batch): CounterSnapshot;
}
