<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Web;

use BAGArt\TelegramBotMenu\Contracts\TgWebApiHandlerContract;
use BAGArt\TelegramBotMenu\Manifest\ChatScope;
use BAGArt\TelegramBotMenu\Manifest\EffectiveRole;
use BAGArt\TelegramBotMenu\Support\TgWebApiRoute;
use BAGArt\TelegramBotMenu\Support\TgWebRequest;
use BAGArt\TelegramBotMenu\Support\TgWebResponse;
use BAGArt\TelegramBotAntispam\Http\Controllers\Support\AntispamEffectivePlan;

/**
 * webApi surface for the anti-spam module (menu_integration.md M-5): exposes
 * the effective evaluation plan (merged settings + DB overrides, same merge
 * order as the webhook pipeline) to the menu hub. Read-only v1 — captcha and
 * appeals stay in-chat by design (see AntispamWebUi).
 */
final readonly class AntispamUiHandler implements TgWebApiHandlerContract
{
    /** @return list<TgWebApiRoute> */
    public static function routes(): array
    {
        return [
            new TgWebApiRoute('GET', 'status', EffectiveRole::Admin, chatScope: ChatScope::Required),
        ];
    }

    public function handle(TgWebRequest $request, array $path): TgWebResponse
    {
        if ($path === ['status']) {
            return $this->status($request);
        }

        return TgWebResponse::error('not_found', 'Unknown antispam route.', 404, $request->requestId);
    }

    private function status(TgWebRequest $request): TgWebResponse
    {
        $context = $request->context;
        $chat = $context->chat;

        if ($chat === null) {
            return TgWebResponse::error('chat_required', 'The anti-spam plan is per-chat.', 403, $request->requestId);
        }

        // The dispatcher constructs handlers with no dependencies, so the
        // effective-plan resolver (an auto-wirable set of provider singletons)
        // is resolved lazily from the container.
        $plan = app(AntispamEffectivePlan::class)->plan($context->bot->id, $chat->id);

        return TgWebResponse::ok([
            'policyVersion' => $plan->policyVersion,
            'rulesetVersion' => $plan->rulesetVersion,
            'thresholds' => $plan->thresholds(),
            'globalCap' => $plan->globalCap,
            'enabledRules' => array_values(array_filter($plan->enabledRules)),
        ]);
    }
}
