<?php

declare(strict_types=1);

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Modules\ModuleSettingsContract;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\Processing\RegisteredUpdateProcessorSelector;
use BAGArt\TelegramBot\Configs\TgServiceConfig;
use BAGArt\TelegramBotAntispam\Processors\AntispamReportCommand;
use BAGArt\TelegramBotAntispam\Processors\AntispamStatusCommand;
use BAGArt\TelegramBotManagement\Models\TgModuleEnablement;

require_once __DIR__.'/AntispamHelpers.php';

beforeEach(function () {
    config(['antispam.counter_driver' => 'memory']);

    \BAGArt\TelegramBotManagement\Models\TgBot::create(['bot_id' => 'test_bot', 'token' => 't:token']);
    TgModuleEnablement::factory()->forChat('test_bot', 100)->enabled(true)->create();
});

function statusCommand(TgSenderContract $spy): AntispamStatusCommand
{
    return new AntispamStatusCommand(
        sender: $spy,
        dryRun: app(\BAGArt\TelegramBotAntispam\DryRun\DryRunExecutor::class),
        settings: app(ModuleSettingsContract::class),
    );
}

it('routes /antispam to the module processor via the selector', function () {
    $factory = app(\BAGArt\TelegramBot\TgBotSetupFactory::class);
    $selector = new RegisteredUpdateProcessorSelector(
        serviceConfig: new TgServiceConfig(),
        botSetup: $factory->create(serviceConfig: new TgServiceConfig()),
    );

    $botConfig = new TgBotConfig(token: 'x:token', botId: 'test_bot');
    $found = [];
    foreach ($selector->selectProcessors(
        new \BAGArt\TelegramBot\TgApi\Types\DTO\UpdateTypeDTO(updateId: 1, message: antispamMessage(100, 42, '/antispam')),
        $botConfig,
    ) as $processors) {
        foreach ($processors as $processor) {
            $found[] = $processor::class;
        }
    }

    expect($found)->toContain(AntispamStatusCommand::class);
});

it('answers /antispam with a status message', function () {
    $spy = senderSpy();

    statusCommand($spy)->process(
        antispamMessage(100, 42, '/antispam'),
        new TgBotConfig(token: 'x:token', botId: 'test_bot'),
    );

    expect($spy->sent)->toContain(\BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO::class);
});

it('dry-runs "/antispam test <text>" and reports the breakdown', function () {
    $spy = senderSpy();

    statusCommand($spy)->process(
        antispamMessage(100, 42, '/antispam test join t.me/spam_channel now'),
        new TgBotConfig(token: 'x:token', botId: 'test_bot'),
    );

    expect($spy->dtos)->not->toBeEmpty()
        ->and($spy->dtos[0]->text)->toContain('Dry-run')
        ->and($spy->dtos[0]->text)->toContain('advertising.regex');
});

it('acknowledges /report as a reply', function () {
    $spy = senderSpy();

    $message = antispamMessage(100, 42, '/report');
    $message->replyToMessage = antispamMessage(100, 7, 'spam spam spam', 99);

    (new AntispamReportCommand($spy))->process(
        $message,
        new TgBotConfig(token: 'x:token', botId: 'test_bot'),
    );

    expect($spy->sent)->toContain(\BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO::class)
        ->and($spy->dtos[0]->text)->toContain('Report accepted');
});
