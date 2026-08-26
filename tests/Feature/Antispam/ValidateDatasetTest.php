<?php

declare(strict_types=1);

use BAGArt\TelegramBotAntispam\Commands\ValidateDatasetCommand;

it('runs the validation harness over a labeled dataset and prints the confusion matrix', function () {
    $dataset = [
        ['text' => 'hello everyone, nice weather today', 'expected' => 'allow'],
        ['text' => 'thanks, that fixed it for me too', 'expected' => 'allow'],
        ['text' => 'join t.me/spam_channel now', 'expected' => 'restrict'],
        ['text' => 'казино онлайн, быстрые выплаты!', 'expected' => 'ban'],
        ['text' => 'weird row', 'expected' => 'nonsense'],
    ];
    $path = tempnam(sys_get_temp_dir(), 'antispam-dataset');
    file_put_contents((string) $path, json_encode($dataset));

    $this->artisan(ValidateDatasetCommand::class, ['file' => $path])
        ->expectsOutputToContain('Rows: 4')
        ->assertSuccessful();

    unlink((string) $path);
});

it('fails cleanly on a missing or invalid dataset', function () {
    $this->artisan(ValidateDatasetCommand::class, ['file' => '/nonexistent.json'])
        ->assertFailed();

    $path = tempnam(sys_get_temp_dir(), 'antispam-dataset');
    file_put_contents((string) $path, '{"not": "a list"}');

    $this->artisan(ValidateDatasetCommand::class, ['file' => $path])
        ->assertFailed();

    unlink((string) $path);
});
