<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Web;

use BAGArt\TelegramBotAntispam\AntispamPipeline;
use BAGArt\TelegramBotMenu\Contracts\TgSettingsFormContract;
use BAGArt\TelegramBotMenu\Contracts\TgWebUiContract;
use BAGArt\TelegramBotMenu\Manifest\TgWebUiManifest;
use BAGArt\TelegramBotMenu\Manifest\UiAudience;
use BAGArt\TelegramBotMenu\Manifest\UiEntry;
use BAGArt\TelegramBotMenu\Manifest\UiField;
use BAGArt\TelegramBotMenu\Manifest\UiFieldType;
use BAGArt\TelegramBotMenu\Manifest\UiGroup;
use BAGArt\TelegramBotMenu\Manifest\UiKind;
use InvalidArgumentException;

/**
 * Menu-hub settings surface for antispam (menu_integration.md M-5): the
 * moderator-facing policy knobs (strictness preset + global score cap)
 * exposed as a §8.3 schema form over the same module_settings row the
 * PolicyCompiler reads — one source of truth, no mirrored state.
 *
 * Captcha and /appeal conversations stay in-chat by design (G9 of the plan):
 * security interactions must not move to the web surface.
 */
final class AntispamWebUi implements TgSettingsFormContract, TgWebUiContract
{
    public const string STRICTNESS_DEFAULT = 'normal';

    public const array STRICTNESS_OPTIONS = ['relaxed', 'normal', 'strict'];

    public static function manifest(): TgWebUiManifest
    {
        return new TgWebUiManifest(
            moduleId: AntispamPipeline::MODULE_ID,
            title: 'Anti-Spam',
            icon: '🛡',
            kind: UiKind::Management,
            minAudience: UiAudience::Admin,
            description: 'Spam protection policy for the chat',
            entry: UiEntry::schema([
                UiGroup::of('policy', 'Policy', [
                    UiField::enum('strictness', 'Strictness preset', options: [
                        ['value' => 'relaxed', 'label' => 'Relaxed (60/120/225)'],
                        ['value' => 'normal', 'label' => 'Normal (40/80/150)'],
                        ['value' => 'strict', 'label' => 'Strict (24/48/90)'],
                    ], default: self::STRICTNESS_DEFAULT),
                    new UiField('global_cap', 'Global score cap per user', UiFieldType::Int, default: 200, extra: ['min' => 50, 'max' => 1000], help: 'Hard ceiling on the accumulated violation score'),
                ]),
            ]),
            sortKey: 'antispam',
            memberReadVisible: true,
        );
    }

    /** @return array<string, array<string, string>> */
    public static function translations(): array
    {
        return [
            'ru' => [
                'Anti-Spam' => 'Анти-спам',
                'Spam protection policy for the chat' => 'Политика защиты от спама в чате',
                'Strictness preset' => 'Уровень строгости',
                'Global score cap per user' => 'Глобальный лимит баллов на пользователя',
            ],
        ];
    }

    public function validate(array $raw): array
    {
        $patch = [];

        if (array_key_exists('strictness', $raw)) {
            $strictness = (string) $raw['strictness'];

            if (! in_array($strictness, self::STRICTNESS_OPTIONS, true)) {
                throw new InvalidArgumentException('Invalid strictness value.');
            }

            $patch['strictness'] = $strictness;
        }

        if (array_key_exists('global_cap', $raw)) {
            $patch['global_cap'] = max(50, min(1000, (int) $raw['global_cap']));
        }

        return $patch;
    }

    /**
     * The engine operates with built-in defaults for every unset key, so a
     * freshly enabled module is always "configured" — there is no setup step
     * the web surface could demand.
     */
    public function isConfigured(array $settings): bool
    {
        return true;
    }
}
