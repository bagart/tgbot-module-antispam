<?php

declare(strict_types=1);

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\Processing\RegisteredUpdateProcessorSelector;
use BAGArt\TelegramBot\Configs\TgServiceConfig;
use BAGArt\TelegramBotAntispam\Appeals\AppealManager;
use BAGArt\TelegramBotAntispam\Models\AntispamAppeal;
use BAGArt\TelegramBotAntispam\Models\AntispamViolation;
use BAGArt\TelegramBotAntispam\Processors\AppealCommand;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotManagement\Models\TgModuleEnablement;

require_once __DIR__.'/AntispamHelpers.php';

beforeEach(function () {
    config(['antispam.counter_driver' => 'memory']);

    TgBot::create(['bot_id' => 'test_bot', 'token' => 't:token']);
    TgModuleEnablement::factory()
        ->forChat('test_bot', 100)
        ->enabled(true)
        ->create(['module_id' => 'antispam']);
});

function sanctionViolation(string $action = 'restrict'): AntispamViolation
{
    return AntispamViolation::factory()
        ->forScope('test_bot', 100, 42)
        ->create([
            'enforcement_action' => $action,
            'status' => AntispamViolation::STATUS_APPLIED,
            'verdict' => ['action' => $action, 'policyVersion' => 'antispam.policy.v1'],
        ]);
}

function appealCommand(TgSenderContract $spy): AppealCommand
{
    return new AppealCommand(sender: $spy, appeals: app(AppealManager::class));
}

it('routes /appeal to the module processor via the selector', function () {
    $factory = app(\BAGArt\TelegramBot\TgBotSetupFactory::class);
    $selector = new RegisteredUpdateProcessorSelector(
        serviceConfig: new TgServiceConfig(),
        botSetup: $factory->create(serviceConfig: new TgServiceConfig()),
    );

    $botConfig = new TgBotConfig(token: 'x:token', botId: 'test_bot');
    $found = [];
    foreach ($selector->selectProcessors(
        new \BAGArt\TelegramBot\TgApi\Types\DTO\UpdateTypeDTO(updateId: 1, message: antispamMessage(100, 42, '/appeal sorry')),
        $botConfig,
    ) as $processors) {
        foreach ($processors as $processor) {
            $found[] = $processor::class;
        }
    }

    expect($found)->toContain(AppealCommand::class);
});

it('files a pending appeal against the latest sanction', function () {
    $violation = sanctionViolation();
    $spy = senderSpy();

    appealCommand($spy)->process(
        antispamMessage(100, 42, '/appeal it was a family link'),
        new TgBotConfig(token: 'x:token', botId: 'test_bot'),
    );

    $appeal = AntispamAppeal::query()->where('user_id', 42)->first();
    expect($appeal)->not->toBeNull()
        ->and($appeal->violation_id)->toBe((string) $violation->id)
        ->and($appeal->status)->toBe(AntispamAppeal::STATUS_PENDING)
        ->and($appeal->message)->toBe('it was a family link')
        ->and($spy->dtos[0]->text)->toContain('Appeal filed');
});

it('rejects duplicate pending appeals', function () {
    sanctionViolation();
    $spy = senderSpy();
    $botConfig = new TgBotConfig(token: 'x:token', botId: 'test_bot');

    appealCommand($spy)->process(antispamMessage(100, 42, '/appeal first'), $botConfig);
    appealCommand($spy)->process(antispamMessage(100, 42, '/appeal second'), $botConfig);

    expect(AntispamAppeal::query()->where('user_id', 42)->count())->toBe(1)
        ->and($spy->dtos[1]->text)->toContain('already have a pending appeal');
});

it('does not file an appeal without an active sanction', function () {
    AntispamViolation::factory()->forScope('test_bot', 100, 42)->create([
        'enforcement_action' => 'warn',
        'status' => AntispamViolation::STATUS_APPLIED,
    ]);
    $spy = senderSpy();

    appealCommand($spy)->process(
        antispamMessage(100, 42, '/appeal why me'),
        new TgBotConfig(token: 'x:token', botId: 'test_bot'),
    );

    expect(AntispamAppeal::query()->count())->toBe(0)
        ->and($spy->dtos[0]->text)->toContain('no active sanction');
});

it('requires a reason', function () {
    sanctionViolation();
    $spy = senderSpy();

    appealCommand($spy)->process(
        antispamMessage(100, 42, '/appeal'),
        new TgBotConfig(token: 'x:token', botId: 'test_bot'),
    );

    expect(AntispamAppeal::query()->count())->toBe(0)
        ->and($spy->dtos[0]->text)->toContain('Usage');
});
