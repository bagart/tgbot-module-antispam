<?php

/**
 * Load-run teardown: removes the load_bot rows and the secret file.
 */

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

DB::table('tg_module_enablements')->where('bot_id', 'load_bot')->delete();
DB::table('tg_bots')->where('bot_id', 'load_bot')->delete();
@unlink('/tmp/load-run-secret.txt');
echo "cleaned\n";
