<?php

declare(strict_types=1);

use BAGArt\TelegramBotMenu\Manifest\ChatScope;
use BAGArt\TelegramBotMenu\Manifest\EffectiveRole;
use BAGArt\TelegramBotMenu\Support\BotRef;
use BAGArt\TelegramBotMenu\Support\ChatRef;
use BAGArt\TelegramBotMenu\Support\ModuleRef;
use BAGArt\TelegramBotMenu\Support\TgUiContext;
use BAGArt\TelegramBotMenu\Support\TgWebRequest;
use BAGArt\TelegramBotMenu\Support\UserRef;
use BAGArt\TelegramBotAntispam\Web\AntispamUiHandler;

/**
 * menu_integration.md M-5: the read-only webApi surface exposing the
 * effective anti-spam plan (module settings + DB overrides) to the menu hub.
 */
it('declares a chat-scoped admin status route', function () {
    $routes = AntispamUiHandler::routes();

    expect($routes)->toHaveCount(1)
        ->and($routes[0]->method)->toBe('GET')
        ->and($routes[0]->path)->toBe('status')
        ->and($routes[0]->minRole)->toBe(EffectiveRole::Admin)
        ->and($routes[0]->chatScope)->toBe(ChatScope::Required);
});

it('answers 404 for unknown routes from the context alone', function () {
    $handler = new AntispamUiHandler();
    $request = antispamWebRequest(EffectiveRole::Admin, 900);

    $response = $handler->handle($request, ['unknown']);

    expect($response->status)->toBe(404)
        ->and($response->body['error']['code'])->toBe('not_found');
});

it('refuses a chatless status request even if the dispatcher is bypassed', function () {
    $handler = new AntispamUiHandler();
    $request = antispamWebRequest(EffectiveRole::Admin, null);

    $response = $handler->handle($request, ['status']);

    expect($response->status)->toBe(403)
        ->and($response->body['error']['code'])->toBe('chat_required');
});

/**
 * @param EffectiveRole $role
 * @param int|null $chatId
 */
function antispamWebRequest(EffectiveRole $role, ?int $chatId): TgWebRequest
{
    $context = new TgUiContext(
        bot: new BotRef('7001', 'antispambot'),
        chat: $chatId === null ? null : new ChatRef($chatId, 'Defended chat', 'supergroup'),
        module: new ModuleRef('antispam'),
        role: $role,
        user: new UserRef(42, 'Admin', 'en'),
    );

    return new TgWebRequest(
        botId: '7001',
        tgUserId: 42,
        role: $role,
        chatId: $chatId,
        locale: 'en',
        payload: [],
        requestId: 'req-1',
        context: $context,
    );
}
