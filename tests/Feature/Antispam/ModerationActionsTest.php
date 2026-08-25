<?php

declare(strict_types=1);

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\TgApi\Methods\DTO\BanChatMemberMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\DTO\DeleteMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\DTO\RestrictChatMemberMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\DTO\UnbanChatMemberMethodDTO;
use BAGArt\TelegramBotAntispam\Enforcement\ActionExecutor;
use BAGArt\TelegramBotAntispam\Moderation\AntispamModerationService;
use BAGArt\TelegramBotAntispam\Models\AntispamViolation;
use BAGArt\TelegramBotAntispam\Violation\ViolationRecorder;

require_once __DIR__.'/AntispamHelpers.php';

function moderationViolation(string $status = AntispamViolation::STATUS_PENDING, string $action = 'restrict'): AntispamViolation
{
    return AntispamViolation::factory()
        ->forScope('test_bot', 100, 42)
        ->create([
            'enforcement_action' => $action,
            'status' => $status,
            'verdict' => ['action' => $action, 'policyVersion' => 'antispam.policy.v1'],
        ]);
}

function moderationService($spy): AntispamModerationService
{
    return new AntispamModerationService(
        sender: $spy,
        executor: new ActionExecutor(sender: $spy, recorder: new ViolationRecorder()),
    );
}

function moderationBotConfig(): TgBotConfig
{
    return new TgBotConfig(token: 'x:token', botId: 'test_bot');
}

it('applies a pending violation through the enforcement executor', function () {
    $violation = moderationViolation(action: 'ban');
    $spy = senderSpy();
    $service = moderationService($spy);

    expect($service->applyViolation($violation, moderationBotConfig()))->toBeTrue()
        ->and($spy->sent)->toBe([DeleteMessageMethodDTO::class, BanChatMemberMethodDTO::class])
        ->and($violation->refresh()->status)->toBe(AntispamViolation::STATUS_APPLIED);
});

it('refuses to re-apply an already handled violation', function () {
    $violation = moderationViolation(status: AntispamViolation::STATUS_APPLIED);
    $spy = senderSpy();

    expect(moderationService($spy)->applyViolation($violation, moderationBotConfig()))->toBeFalse()
        ->and($spy->sent)->toBeEmpty();
});

it('overturns an applied violation and lifts the sanction', function () {
    $violation = moderationViolation(status: AntispamViolation::STATUS_APPLIED, action: 'ban');
    $spy = senderSpy();

    expect(moderationService($spy)->overturn($violation, moderationBotConfig()))->toBeTrue()
        ->and($spy->sent)->toContain(UnbanChatMemberMethodDTO::class)
        ->and($violation->refresh()->status)->toBe(AntispamViolation::STATUS_OVERTURNED);
});

it('overturns a pending violation without side effects', function () {
    $violation = moderationViolation();
    $spy = senderSpy();

    expect(moderationService($spy)->overturn($violation, moderationBotConfig()))->toBeTrue()
        ->and($spy->sent)->toBeEmpty()
        ->and($violation->refresh()->status)->toBe(AntispamViolation::STATUS_OVERTURNED);
});

it('is idempotent when overturning twice', function () {
    $violation = moderationViolation(status: AntispamViolation::STATUS_APPLIED, action: 'restrict');
    $spy = senderSpy();
    $service = moderationService($spy);

    $service->overturn($violation, moderationBotConfig());

    expect($service->overturn($violation, moderationBotConfig()))->toBeFalse()
        ->and(count($spy->sent))->toBe(1)
        ->and($violation->refresh()->status)->toBe(AntispamViolation::STATUS_OVERTURNED);
});

it('escalates a pending soft action to a mute', function () {
    $violation = moderationViolation(action: 'warn');
    $spy = senderSpy();

    expect(moderationService($spy)->escalate($violation, moderationBotConfig()))->toBeTrue()
        ->and($spy->sent)->toContain(DeleteMessageMethodDTO::class, RestrictChatMemberMethodDTO::class)
        ->and($violation->refresh()->enforcement_action)->toBe('restrict')
        ->and($violation->status)->toBe(AntispamViolation::STATUS_ESCALATED);
});

it('escalates a restriction to a ban', function () {
    $violation = moderationViolation(status: AntispamViolation::STATUS_APPLIED, action: 'restrict');
    $spy = senderSpy();

    expect(moderationService($spy)->escalate($violation, moderationBotConfig()))->toBeTrue()
        ->and($spy->sent)->toContain(BanChatMemberMethodDTO::class)
        ->and($violation->refresh()->enforcement_action)->toBe('ban')
        ->and($violation->status)->toBe(AntispamViolation::STATUS_ESCALATED);
});

it('cannot escalate past a ban or an escalated state', function () {
    $banned = moderationViolation(status: AntispamViolation::STATUS_PENDING, action: 'ban');
    $escalated = moderationViolation(status: AntispamViolation::STATUS_ESCALATED, action: 'restrict');
    $spy = senderSpy();

    expect(moderationService($spy)->escalate($banned, moderationBotConfig()))->toBeFalse()
        ->and(moderationService($spy)->escalate($escalated, moderationBotConfig()))->toBeFalse()
        ->and($spy->sent)->toBeEmpty();
});
