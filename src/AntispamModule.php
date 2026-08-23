<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam;

use BAGArt\TelegramBot\Modules\TgModuleCapability;
use BAGArt\TelegramBot\Modules\TgModuleContract;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use BAGArt\TelegramBot\Modules\TgModuleRegistrar;
use BAGArt\TelegramBot\TgApi\Types\DTO\MessageTypeDTO;
use BAGArt\TelegramBotAntispam\Processors\AntispamMessageProcessor;
use BAGArt\TelegramBotAntispam\Processors\AntispamReportCommand;
use BAGArt\TelegramBotAntispam\Processors\AntispamStatusCommand;

/**
 * Anti-spam platform module.
 *
 * failClosed: true — on enablement-storage DB errors the module is treated as
 * DISABLED (spam passes). The field name is the platform's; the real semantics
 * is fail-OPEN for enforcement (RFC Q-D2). Renaming to
 * `onEnablementError: disable` is a separate platform task.
 */
final class AntispamModule implements TgModuleContract
{
    public static function descriptor(): TgModuleDescriptor
    {
        return new TgModuleDescriptor(
            id: AntispamPipeline::MODULE_ID,
            name: 'Anti-Spam',
            version: '1.0.0',
            capabilities: [
                TgModuleCapability::Processor,
                TgModuleCapability::Command,
                TgModuleCapability::Rule,
            ],
            defaultEnabled: false,
            failClosed: true,
        );
    }

    public static function register(TgModuleRegistrar $registrar): void
    {
        $registrar
            ->processor(MessageTypeDTO::class, AntispamMessageProcessor::class)
            ->command(AntispamStatusCommand::NAME, AntispamStatusCommand::class)
            ->command(AntispamReportCommand::NAME, AntispamReportCommand::class);
    }
}
