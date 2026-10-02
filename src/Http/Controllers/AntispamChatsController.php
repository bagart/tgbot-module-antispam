<?php

namespace BAGArt\TelegramBotAntispam\Http\Controllers;

use BAGArt\TelegramBotAntispam\Http\Controllers\Support\AntispamEffectivePlan;
use BAGArt\TelegramBot\Contracts\Modules\ModuleEnablementContract;
use BAGArt\TelegramBot\Contracts\Modules\ModuleSettingsContract;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotAntispam\AntispamPipeline;
use BAGArt\TelegramBotAntispam\Models\AntispamRuleModel;
use BAGArt\TelegramBotAntispam\Rules\RuleRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AntispamChatsController
{
    public function __construct(
        private readonly AntispamEffectivePlan $effectivePlan,
        private readonly ModuleEnablementContract $enablements,
        private readonly ModuleSettingsContract $settings,
    ) {
    }

    public function index(): Response
    {
        $chats = collect($this->settings->chatsWithSettings(AntispamPipeline::MODULE_ID))
            ->filter(fn (array $row): bool => $row['chatId'] !== null)
            ->sortBy('chatId')
            ->sortBy('botId')
            ->values()
            ->map(function (array $row): array {
                $botId = (string) $row['botId'];
                $chatId = (int) $row['chatId'];

                return [
                    'botId' => $botId,
                    'chatId' => $chatId,
                    'enabled' => $this->enablements->isEnabled(AntispamPipeline::MODULE_ID, $botId, $chatId),
                    'settings' => $this->chatSettings((array) $row['settings']),
                    // Effective plan preview — proves the compiled plan picks settings up
                    'rulesetVersion' => $this->effectivePlan->plan($botId, $chatId)->rulesetVersion,
                ];
            });

        return Inertia::render('antispam/chats', [
            'chats' => $chats,
            'bots' => TgBot::query()->orderBy('bot_id')->get(['bot_id']),
            'knownRuleIds' => $this->knownRuleIds(),
        ]);
    }

    /**
     * Saves chat-level antispam settings through ModuleSettingsContract
     * (driver-agnostic merge at the chat scope; null patch values inherit).
     * custom_rules semantics: null = inherit/all active, non-empty list = allowlist,
     * [] is invalid (explicitly rejected below).
     */
    public function updateSettings(Request $request, string $botId, int $chatId): RedirectResponse
    {
        $validated = $request->validate([
            'strictness' => ['nullable', Rule::in(['relaxed', 'normal', 'strict'])],
            'thresholds' => ['nullable', 'array'],
            'thresholds.warn' => ['required_with:thresholds', 'integer', 'min:1', 'max:1000'],
            'thresholds.restrict' => ['required_with:thresholds', 'integer', 'min:1', 'max:1000'],
            'thresholds.ban' => ['required_with:thresholds', 'integer', 'min:1', 'max:1000'],
            'group_caps' => ['nullable', 'array'],
            'group_caps.*' => ['integer', 'min:1', 'max:1000'],
            'custom_rules' => ['nullable', 'array'],
            'custom_rules.*' => ['string'],
            'captcha_enabled' => ['nullable', 'boolean'],
            'captcha_on_fail' => ['nullable', Rule::in(['ban', 'kick'])],
            'captcha_ttl_seconds' => ['nullable', 'integer', 'min:30', 'max:3600'],
            'captcha_whitelist_seconds' => ['nullable', 'integer', 'min:60', 'max:86400'],
            'honeypot_words' => ['nullable', 'string', 'max:2000'],
        ]);

        if (($validated['custom_rules'] ?? null) === []) {
            throw ValidationException::withMessages([
                'custom_rules' => 'An empty allowlist would disable every rule; clear the field to inherit instead.',
            ]);
        }

        foreach ((array) ($validated['custom_rules'] ?? []) as $ruleId) {
            if (! in_array($ruleId, $this->knownRuleIds(), true)) {
                throw ValidationException::withMessages([
                    'custom_rules' => "Unknown rule id [{$ruleId}].",
                ]);
            }
        }

        $this->settings->patchSettings(
            AntispamPipeline::MODULE_ID,
            $botId,
            $chatId,
            $this->buildSettingsPatch($validated),
        );

        // Drop enablement + settings caches so the next webhook recompiles
        $this->enablements->refresh($botId, $chatId);

        return to_route('antispam.chats.index');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed> null values mean "remove the key" (inherit)
     */
    private function buildSettingsPatch(array $validated): array
    {
        $patch = [];

        if (! empty($validated['strictness'])) {
            $patch['strictness'] = $validated['strictness'];
        }
        if (! empty($validated['thresholds'])) {
            $patch['thresholds'] = $validated['thresholds'];
        }
        if (! empty($validated['group_caps'])) {
            $patch['group_caps'] = $validated['group_caps'];
        }

        if (array_key_exists('custom_rules', $validated)) {
            if ($validated['custom_rules'] === null) {
                $patch['disabled_rules'] = null; // inherit: all active rules on
            } else {
                $allowlist = array_values((array) $validated['custom_rules']);
                $patch['disabled_rules'] = collect($this->knownRuleIds())
                    ->reject(fn (string $id): bool => in_array($id, $allowlist, true))
                    ->mapWithKeys(fn (string $id): array => [$id => false])
                    ->all();
            }
        }

        // CAPTCHA: enabled=false removes the key (inherit = off); enabled=true stores the map
        if (array_key_exists('captcha_enabled', $validated)) {
            if ($validated['captcha_enabled'] === true) {
                $patch['captcha'] = [
                    'enabled' => true,
                    'on_fail' => $validated['captcha_on_fail'] ?? 'ban',
                    'ttl_seconds' => $validated['captcha_ttl_seconds'] ?? 300,
                    'whitelist_seconds' => $validated['captcha_whitelist_seconds'] ?? 3600,
                ];
            } else {
                $patch['captcha'] = null;
            }
        }

        // Honeypot (P3.8): comma-separated trigger words; empty string removes the key
        if (array_key_exists('honeypot_words', $validated)) {
            $words = array_values(array_filter(array_map(
                fn (string $word): string => trim($word),
                explode(',', (string) $validated['honeypot_words']),
            )));
            $patch['honeypot'] = $words === [] ? null : ['words' => $words];
        }

        return $patch;
    }

    /**
     * @param  array<string, mixed>  $settings  raw stored chat-scope settings
     * @return array<string, mixed>
     */
    private function chatSettings(array $settings): array
    {
        return [
            'strictness' => $settings['strictness'] ?? null,
            'thresholds' => $settings['thresholds'] ?? null,
            'group_caps' => $settings['group_caps'] ?? null,
            // disabled_rules map → allowlist view for the form (null = inherit)
            'customRules' => isset($settings['disabled_rules'])
                ? array_values(array_diff($this->knownRuleIds(), array_keys((array) $settings['disabled_rules'])))
                : null,
            'honeypotWords' => isset($settings['honeypot']['words'])
                ? implode(', ', (array) $settings['honeypot']['words'])
                : '',
            'captcha' => isset($settings['captcha']) && is_array($settings['captcha'])
                ? [
                    'enabled' => (bool) ($settings['captcha']['enabled'] ?? false),
                    'onFail' => (string) ($settings['captcha']['on_fail'] ?? 'ban'),
                    'ttlSeconds' => (int) ($settings['captcha']['ttl_seconds'] ?? 300),
                ]
                : null,
        ];
    }

    /**
     * @return list<string>
     */
    private function knownRuleIds(): array
    {
        // MessageRateRule emits window-suffixed ids (flood.rate.burst, …)
        $rateWindows = ['flood.rate.burst', 'flood.rate.short', 'flood.rate.medium', 'flood.rate.long'];

        return collect(iterator_to_array(app(RuleRegistry::class)))
            ->map(fn ($r) => $r->id())
            ->merge(AntispamRuleModel::query()->pluck('name'))
            ->merge($rateWindows)
            ->unique()
            ->values()
            ->all();
    }
}
