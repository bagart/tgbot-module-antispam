<?php

declare(strict_types=1);

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\Processing\RegisteredUpdateProcessorSelector;
use BAGArt\TelegramBot\Configs\TgServiceConfig;
use BAGArt\TelegramBot\TgApi\Types\DTO\BanChatMemberMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\CallbackQueryTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatMemberTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatMemberUpdatedTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\RestrictChatMemberMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\SendMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\UnbanChatMemberMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\UserTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\Enum\ChatPropTypeEnum;
use BAGArt\TelegramBotAntispam\Captcha\CaptchaService;
use BAGArt\TelegramBotAntispam\Models\AntispamUserListEntry;
use BAGArt\TelegramBotAntispam\Processors\CaptchaCallbackProcessor;
use BAGArt\TelegramBotAntispam\Processors\CaptchaJoinProcessor;
use BAGArt\TelegramBotAntispam\UserList\UserListManager;
use BAGArt\TelegramBotManagement\Models\TgModuleEnablement;

require_once __DIR__.'/AntispamHelpers.php';

const CAPTCHA_SETTINGS = [
    'captcha' => [
        'enabled' => true,
        'on_fail' => 'ban',
        'ttl_seconds' => 300,
        'whitelist_seconds' => 3600,
    ],
];

function captchaSettingsRow(string $botId = 'test_bot', int $chatId = 100, array $settings = CAPTCHA_SETTINGS): void
{
    TgModuleEnablement::factory()
        ->forChat($botId, $chatId)
        ->enabled(true)
        ->create(['module_id' => 'antispam', 'module_settings' => $settings]);
}

/**
 * The lib does not implement the ChatMember* oneOf contract yet: hydrated
 * members are property-less ChatMemberTypeDTO placeholders. This subclass
 * simulates the facts a future oneOf-aware mapper will provide.
 */
final class CaptchaTestMemberDTO extends ChatMemberTypeDTO
{
    public function __construct(
        public readonly UserTypeDTO $user,
        public readonly string $status,
    ) {
        parent::__construct();
    }
}

function joinEvent(int $chatId, int $userId, bool $isBot = false): ChatMemberUpdatedTypeDTO
{
    return new ChatMemberUpdatedTypeDTO(
        chat: new ChatTypeDTO(id: (string) $chatId, type: ChatPropTypeEnum::SUPERGROUP),
        from: new UserTypeDTO(id: (string) $userId, isBot: false, firstName: 'Joiner'),
        date: time(),
        oldChatMember: new CaptchaTestMemberDTO(
            user: new UserTypeDTO(id: (string) $userId, isBot: $isBot, firstName: 'Joiner'),
            status: 'left',
        ),
        newChatMember: new CaptchaTestMemberDTO(
            user: new UserTypeDTO(id: (string) $userId, isBot: $isBot, firstName: 'Joiner'),
            status: 'member',
        ),
    );
}

function joinEventWithPlaceholderMembers(int $chatId, int $userId): ChatMemberUpdatedTypeDTO
{
    return new ChatMemberUpdatedTypeDTO(
        chat: new ChatTypeDTO(id: (string) $chatId, type: ChatPropTypeEnum::SUPERGROUP),
        from: new UserTypeDTO(id: (string) $userId, isBot: false, firstName: 'Joiner'),
        date: time(),
        oldChatMember: new ChatMemberTypeDTO(),
        newChatMember: new ChatMemberTypeDTO(),
    );
}

function callbackEvent(int $fromUserId, string $data): CallbackQueryTypeDTO
{
    return new CallbackQueryTypeDTO(
        id: 'cbq_'.$fromUserId,
        from: new UserTypeDTO(id: (string) $fromUserId, isBot: false, firstName: 'Clicker'),
        chatInstance: 'ci',
        data: $data,
    );
}

function captchaService(TgSenderContract $spy): CaptchaService
{
    app()->instance(TgSenderContract::class, $spy);

    return app(CaptchaService::class);
}

beforeEach(function () {
    config(['antispam.counter_driver' => 'memory']);

    \BAGArt\TelegramBotManagement\Models\TgBot::create(['bot_id' => 'test_bot', 'token' => 't:token']);
});

it('routes join events and captcha callbacks through the selector', function () {
    captchaSettingsRow();
    $factory = app(\BAGArt\TelegramBot\TgBotSetupFactory::class);
    $selector = new RegisteredUpdateProcessorSelector(
        serviceConfig: new TgServiceConfig(),
        botSetup: $factory->create(serviceConfig: new TgServiceConfig()),
    );
    $botConfig = new TgBotConfig(token: 'x:token', botId: 'test_bot');

    $joinFound = [];
    foreach ($selector->selectProcessors(joinEvent(100, 42), $botConfig) as $processors) {
        foreach ($processors as $processor) {
            $joinFound[] = $processor::class;
        }
    }
    expect($joinFound)->toContain(CaptchaJoinProcessor::class);
});

it('challenges a new joiner exactly once (restrict + message)', function () {
    captchaSettingsRow();
    $spy = senderSpy();
    $service = captchaService($spy);
    $botConfig = new TgBotConfig(token: 'x:token', botId: 'test_bot');

    $service->handleJoin(joinEvent(100, 42), $botConfig);
    $service->handleJoin(joinEvent(100, 42), $botConfig);

    $restricts = array_values(array_filter($spy->dtos, fn ($dto) => $dto instanceof RestrictChatMemberMethodDTO));
    $challenges = array_values(array_filter($spy->dtos, fn ($dto) => $dto instanceof SendMessageMethodDTO));

    // Second join event must not re-restrict or re-send (idempotent issue).
    expect(count($restricts))->toBe(1)
        ->and(count($challenges))->toBe(1)
        ->and($challenges[0]->replyMarkup?->inlineKeyboard[0][0]->callbackData)->toStartWith('antispam:captcha:100:42:');
});

it('sends nothing when captcha is disabled for the chat', function () {
    $spy = senderSpy();
    $service = captchaService($spy);
    $botConfig = new TgBotConfig(token: 'x:token', botId: 'test_bot');

    $service->handleJoin(joinEvent(100, 42), $botConfig);

    expect($spy->sent)->toBe([]);
});

it('ignores bots and whitelisted users', function () {
    captchaSettingsRow();
    AntispamUserListEntry::factory()->whitelisted('test_bot', 100, 7)->create();
    app(UserListManager::class)->refresh('test_bot', 100);

    $spy = senderSpy();
    $service = captchaService($spy);
    $botConfig = new TgBotConfig(token: 'x:token', botId: 'test_bot');

    $service->handleJoin(joinEvent(100, 1, isBot: true), $botConfig);
    $service->handleJoin(joinEvent(100, 7), $botConfig);

    expect($spy->sent)->toBe([]);
});

it('degrades to a no-op when member facts are unavailable (lib oneOf gap)', function () {
    captchaSettingsRow();
    $spy = senderSpy();
    $service = captchaService($spy);
    $botConfig = new TgBotConfig(token: 'x:token', botId: 'test_bot');

    $service->handleJoin(joinEventWithPlaceholderMembers(100, 42), $botConfig);

    expect($spy->sent)->toBe([]);
});

it('lifts the restriction and whitelists on a correct answer', function () {
    captchaSettingsRow();
    $spy = senderSpy();
    $service = captchaService($spy);
    $botConfig = new TgBotConfig(token: 'x:token', botId: 'test_bot');
    $service->handleJoin(joinEvent(100, 42), $botConfig);

    $challenge = array_values(array_filter($spy->dtos, fn ($dto) => $dto instanceof SendMessageMethodDTO))[0];
    $data = $challenge->replyMarkup->inlineKeyboard[0][0]->callbackData;
    expect($data)->toEndWith(':ok');

    $spy->sent = [];
    $service->handleCallback(callbackEvent(42, $data), $botConfig);

    expect($spy->sent)->toContain(RestrictChatMemberMethodDTO::class)
        ->not->toContain(BanChatMemberMethodDTO::class);

    $lift = array_values(array_filter($spy->dtos, fn ($dto) => $dto instanceof RestrictChatMemberMethodDTO))[0];
    expect($lift->permissions?->canSendMessages)->toBeTrue();

    $entry = AntispamUserListEntry::query()->where(['user_id' => 42, 'list_type' => 'whitelist'])->sole();
    expect($entry->reason)->toBe('antispam:captcha')
        ->and($entry->expires_at->getTimestamp())->toBeGreaterThan(now()->getTimestamp());
});

it('bans on a wrong answer (default fail mode)', function () {
    captchaSettingsRow();
    $spy = senderSpy();
    $service = captchaService($spy);
    $botConfig = new TgBotConfig(token: 'x:token', botId: 'test_bot');
    $service->handleJoin(joinEvent(100, 42), $botConfig);

    $challenge = array_values(array_filter($spy->dtos, fn ($dto) => $dto instanceof SendMessageMethodDTO))[0];
    $rejectData = $challenge->replyMarkup->inlineKeyboard[0][1]->callbackData;
    expect($rejectData)->toEndWith(':no');

    $spy->sent = [];
    $service->handleCallback(callbackEvent(42, $rejectData), $botConfig);

    expect($spy->sent)->toContain(BanChatMemberMethodDTO::class)
        ->not->toContain(UnbanChatMemberMethodDTO::class);
});

it('kicks instead of banning when on_fail=kick', function () {
    captchaSettingsRow(settings: [
        'captcha' => ['enabled' => true, 'on_fail' => 'kick', 'ttl_seconds' => 120, 'whitelist_seconds' => 600],
    ]);
    $spy = senderSpy();
    $service = captchaService($spy);
    $botConfig = new TgBotConfig(token: 'x:token', botId: 'test_bot');
    $service->handleJoin(joinEvent(100, 42), $botConfig);

    $challenge = array_values(array_filter($spy->dtos, fn ($dto) => $dto instanceof SendMessageMethodDTO))[0];
    $rejectData = $challenge->replyMarkup->inlineKeyboard[0][1]->callbackData;

    $spy->sent = [];
    $service->handleCallback(callbackEvent(42, $rejectData), $botConfig);

    expect($spy->sent)->toContain(BanChatMemberMethodDTO::class)
        ->and($spy->sent)->toContain(UnbanChatMemberMethodDTO::class);
});

it('applies the fail outcome for an expired/unknown token', function () {
    captchaSettingsRow();
    $spy = senderSpy();
    $service = captchaService($spy);
    $botConfig = new TgBotConfig(token: 'x:token', botId: 'test_bot');
    $service->handleJoin(joinEvent(100, 42), $botConfig);

    // Simulate TTL expiry by dropping the pending challenge.
    app(\BAGArt\TelegramBotAntispam\Captcha\CaptchaStore::class)->forget('test_bot', 100, 42);

    $spy->sent = [];
    $service->handleCallback(callbackEvent(42, 'antispam:captcha:100:42:deadbeef:ok'), $botConfig);

    expect($spy->sent)->toContain(BanChatMemberMethodDTO::class);
});

it('ignores clicks from users other than the challenged one', function () {
    captchaSettingsRow();
    $spy = senderSpy();
    $service = captchaService($spy);
    $botConfig = new TgBotConfig(token: 'x:token', botId: 'test_bot');
    $service->handleJoin(joinEvent(100, 42), $botConfig);

    $challenge = array_values(array_filter($spy->dtos, fn ($dto) => $dto instanceof SendMessageMethodDTO))[0];
    $data = $challenge->replyMarkup->inlineKeyboard[0][0]->callbackData;

    $spy->sent = [];
    $service->handleCallback(callbackEvent(43, $data), $botConfig);

    expect($spy->sent)->toHaveCount(1)
        ->and(AntispamUserListEntry::query()->count())->toBe(0);

    // The challenge stays consumable by its owner.
    $service->handleCallback(callbackEvent(42, $data), $botConfig);
    expect(AntispamUserListEntry::query()->count())->toBe(1);
});

it('decodes callback payloads strictly', function () {
    expect(CaptchaService::decode('antispam:captcha:100:42:abc123:ok'))
        ->toBe([100, 42, 'abc123', true])
        ->and(CaptchaService::decode('antispam:captcha:100:42:abc123:no'))
        ->toBe([100, 42, 'abc123', false])
        ->and(CaptchaService::decode('other:prefix:1'))->toBeNull()
        ->and(CaptchaService::decode('antispam:captcha:x:42:tok:ok'))->toBeNull()
        ->and(CaptchaService::decode('antispam:captcha:100:42:tok'))->toBeNull();
});
