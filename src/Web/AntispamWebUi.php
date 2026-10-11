<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Web;

use BAGArt\TelegramBotAntispam\AntispamPermissionResolver;
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
            title: 't:antispam.title',
            icon: '🛡',
            kind: UiKind::Management,
            minAudience: UiAudience::Admin,
            description: 't:antispam.description',
            entry: UiEntry::schema([
                UiGroup::of('policy', 't:antispam.group.policy', [
                    UiField::enum('strictness', 't:antispam.field.strictness', options: [
                        ['value' => 'relaxed', 'label' => 't:antispam.option.relaxed'],
                        ['value' => 'normal', 'label' => 't:antispam.option.normal'],
                        ['value' => 'strict', 'label' => 't:antispam.option.strict'],
                    ], default: self::STRICTNESS_DEFAULT),
                    new UiField('global_cap', 't:antispam.field.global_cap', UiFieldType::Int, default: 200, extra: ['min' => 50, 'max' => 1000], help: 't:antispam.help.global_cap'),
                ]),
            ]),
            permission: AntispamPermissionResolver::CONFIGURE,
            sortKey: 'antispam',
            memberReadVisible: true,
        );
    }

    /** @return array<string, array<string, string>> */
    public static function translations(): array
    {
        return [
            'en' => [
                'antispam.title' => 'Anti-Spam',
                'antispam.description' => 'Spam protection policy for the chat',
                'antispam.group.policy' => 'Policy',
                'antispam.field.strictness' => 'Strictness preset',
                'antispam.option.relaxed' => 'Relaxed (60/120/225)',
                'antispam.option.normal' => 'Normal (40/80/150)',
                'antispam.option.strict' => 'Strict (24/48/90)',
                'antispam.field.global_cap' => 'Global score cap per user',
                'antispam.help.global_cap' => 'Hard ceiling on the accumulated violation score',
            ],
            'ru' => [
                'antispam.title' => 'Анти-спам',
                'antispam.description' => 'Политика защиты от спама в чате',
                'antispam.group.policy' => 'Политика',
                'antispam.field.strictness' => 'Уровень строгости',
                'antispam.option.relaxed' => 'Мягкий (60/120/225)',
                'antispam.option.normal' => 'Обычный (40/80/150)',
                'antispam.option.strict' => 'Строгий (24/48/90)',
                'antispam.field.global_cap' => 'Глобальный лимит баллов на пользователя',
                'antispam.help.global_cap' => 'Жёсткий потолок накопленного нарушения',
            ],
            'fr' => [
                'antispam.title' => 'Anti-Spam',
                'antispam.description' => 'Politique de protection contre le spam du chat',
                'antispam.group.policy' => 'Politique',
                'antispam.field.strictness' => 'Préréglage de sévérité',
                'antispam.option.relaxed' => 'Détendu (60/120/225)',
                'antispam.option.normal' => 'Normal (40/80/150)',
                'antispam.option.strict' => 'Strict (24/48/90)',
                'antispam.field.global_cap' => 'Limite globale de points par utilisateur',
                'antispam.help.global_cap' => 'Plafond dur de la violation accumulée',
            ],
            'es' => [
                'antispam.title' => 'Anti-Spam',
                'antispam.description' => 'Política de protección contra spam del chat',
                'antispam.group.policy' => 'Política',
                'antispam.field.strictness' => 'Perfil de severidad',
                'antispam.option.relaxed' => 'Relajado (60/120/225)',
                'antispam.option.normal' => 'Normal (40/80/150)',
                'antispam.option.strict' => 'Estricto (24/48/90)',
                'antispam.field.global_cap' => 'Límite global de puntos por usuario',
                'antispam.help.global_cap' => 'Techo duro de la violación acumulada',
            ],
            'zh' => [
                'antispam.title' => '反垃圾',
                'antispam.description' => '聊天垃圾防护策略',
                'antispam.group.policy' => '策略',
                'antispam.field.strictness' => '严格程度预设',
                'antispam.option.relaxed' => '宽松 (60/120/225)',
                'antispam.option.normal' => '正常 (40/80/150)',
                'antispam.option.strict' => '严格 (24/48/90)',
                'antispam.field.global_cap' => '每位用户全局分数上限',
                'antispam.help.global_cap' => '累计违规分数的硬性上限',
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
