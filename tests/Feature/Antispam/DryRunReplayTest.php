<?php

declare(strict_types=1);

use BAGArt\TelegramBot\Contracts\Modules\ModuleSettingsContract;
use BAGArt\TelegramBotAntispam\Domain\BehaviorContext;
use BAGArt\TelegramBotAntispam\Domain\MessageData;
use BAGArt\TelegramBotAntispam\Domain\UserContext;
use BAGArt\TelegramBotAntispam\Domain\ChatContext;
use BAGArt\TelegramBotAntispam\DryRun\DryRunExecutor;
use BAGArt\TelegramBotAntispam\Engine\PolicyCompiler;

beforeEach(function () {
    config(['antispam.counter_driver' => 'memory']);
    \BAGArt\TelegramBotManagement\Models\TgBot::create(['bot_id' => 'test_bot', 'token' => 't:token']);
});

it('dry-runs text through the compiled plan without side effects', function () {
    $settings = app(ModuleSettingsContract::class);
    $plan = app(PolicyCompiler::class)->compile('test_bot', 100, $settings->settingsFor('antispam', 'test_bot', 100));

    $context = new \BAGArt\TelegramBotAntispam\Domain\AntispamMessageContext(
        user: new UserContext(userId: 42, username: null, isBot: false),
        chat: new ChatContext(chatId: 100, type: 'group'),
        message: new MessageData(
            messageId: 1,
            date: new DateTimeImmutable(),
            text: 'join t.me/spam_channel now',
            entities: null,
            hasMedia: false,
            mediaKind: null,
            mediaFileId: null,
            hasSticker: false,
            stickerEmoji: null,
            caption: null,
            isForwarded: false,
            isReply: false,
            length: 26,
        ),
        behavior: new BehaviorContext(),
    );

    $report = app(DryRunExecutor::class)->run($context, $plan);

    expect($report->score)->toBeGreaterThanOrEqual(60)
        ->and($report->matchedRules)->not->toBeEmpty()
        ->and($report->verdict->action->value)->toBe('restrict')
        ->and($report->toLines())->toContain('verdict: restrict ('.$report->verdict->reason.')');

    // no side effects
    expect(\BAGArt\TelegramBotAntispam\Models\AntispamViolation::query()->count())->toBe(0);
});

it('replays a stored violation against a stricter plan and shows the delta', function () {
    $violation = \BAGArt\TelegramBotAntispam\Models\AntispamViolation::factory()->create([
        'bot_id' => 'test_bot',
        'chat_id' => 100,
        'user_id' => 42,
        'message_id' => 500,
        'matched_rules' => [
            ['ruleId' => 'advertising.regex', 'score' => 60, 'severity' => 'high', 'kind' => 'hard', 'group' => 'advertising', 'reason' => 'pattern'],
        ],
        'group_breakdown' => ['advertising' => ['contribution' => 60, 'cap' => 80]],
        'score' => 60,
        'verdict' => ['action' => 'warn', 'policyVersion' => 'antispam.policy.v1'],
        'enforcement_action' => 'warn',
    ]);

    $relaxedPlan = new \BAGArt\TelegramBotAntispam\Domain\EvaluationPlan(
        policyVersion: 'v-relaxed',
        rulesetVersion: 'r1',
        warnScore: 40,
        restrictScore: 80,
        banScore: 150,
        globalCap: 200,
    );
    $strictPlan = new \BAGArt\TelegramBotAntispam\Domain\EvaluationPlan(
        policyVersion: 'v-strict',
        rulesetVersion: 'r2',
        warnScore: 20,
        restrictScore: 50,
        banScore: 100,
        globalCap: 200,
    );

    $comparison = app(\BAGArt\TelegramBotAntispam\Replay\ReplayEvaluator::class)->replay($violation, $strictPlan);

    // hard+high sets minimum restrict regardless of thresholds; score 60 в‰Ґ strict restrict(50) too
    expect($comparison->changed())->toBeTrue()
        ->and($comparison->newAction->value)->toBe('restrict')
        ->and($comparison->newVerdict->policyVersion)->toBe('v-strict');
});
