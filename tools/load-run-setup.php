<?php

/**
 * Load-run bootstrap: creates the load_bot row + antispam enablement used by
 * tools/load-webhook.php. Idempotent; paired with load-run-teardown.php.
 */

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$botId = 'load_bot';
$secret = 'load-secret-'.bin2hex(random_bytes(12));

DB::table('tg_bots')->updateOrInsert(
    ['bot_id' => $botId],
    [
        'token' => '123456:load-token',
        'secret_token' => $secret,
        'updated_at' => now(),
        'created_at' => now(),
    ],
);

$existing = DB::table('tg_module_enablements')
    ->where('module_id', 'antispam')
    ->where('bot_id', $botId)
    ->where('chat_id', -1001234)
    ->exists();

if ($existing) {
    DB::table('tg_module_enablements')
        ->where('module_id', 'antispam')
        ->where('bot_id', $botId)
        ->where('chat_id', -1001234)
        ->update(['is_enabled' => true, 'updated_at' => now()]);
} else {
    DB::table('tg_module_enablements')->insert([
        'id' => (string) Illuminate\Support\Str::uuid(),
        'module_id' => 'antispam',
        'bot_id' => $botId,
        'chat_id' => -1001234,
        'is_enabled' => true,
        'module_settings' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

file_put_contents('/tmp/load-run-secret.txt', $secret);
echo "ready\n";
