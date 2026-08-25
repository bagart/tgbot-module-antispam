<?php

namespace BAGArt\TelegramBotAntispam\Http\Controllers\Support;

use BAGArt\TelegramBot\Contracts\Modules\ModuleSettingsContract;
use BAGArt\TelegramBotAntispam\AntispamPipeline;
use BAGArt\TelegramBotAntispam\Domain\EvaluationPlan;
use BAGArt\TelegramBotAntispam\Engine\DbRuleOverrides;
use BAGArt\TelegramBotAntispam\Engine\PolicyCompiler;
use Throwable;

/**
 * Resolves the effective merged settings (module settings + DB rule overrides)
 * and the compiled EvaluationPlan — same merge order as the webhook pipeline,
 * so admin Dry-Run / Replay always judge with what production would use.
 */
final readonly class AntispamEffectivePlan
{
    public function __construct(
        private ModuleSettingsContract $settings,
        private DbRuleOverrides $dbRuleOverrides,
        private PolicyCompiler $compiler,
    ) {
    }

    /** @return array<string, mixed> */
    public function mergedSettings(string $botId, int $chatId): array
    {
        try {
            $moduleSettings = $this->settings->settingsFor(AntispamPipeline::MODULE_ID, $botId, $chatId);
        } catch (Throwable) {
            $moduleSettings = [];
        }

        try {
            return DbRuleOverrides::mergeInto($moduleSettings, $this->dbRuleOverrides->forBot($botId));
        } catch (Throwable) {
            return $moduleSettings;
        }
    }

    public function plan(string $botId, int $chatId): EvaluationPlan
    {
        return $this->compiler->compile($botId, $chatId, $this->mergedSettings($botId, $chatId));
    }
}
