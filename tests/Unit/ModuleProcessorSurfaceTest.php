<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Tests\Unit;

use BAGArt\TelegramBot\Contracts\Processing\Processors\TgModuleProcessorContract;
use PHPUnit\Framework\TestCase;

/**
 * The selector calls moduleId() on every module processor during enablement
 * gating (RegisteredUpdateProcessorSelector), so a broken class reference
 * fatals every dispatched update — it must never regress unnoticed.
 */
final class ModuleProcessorSurfaceTest extends TestCase
{
    /** @var list<class-string<TgModuleProcessorContract>> */
    private const array PROCESSORS = [
        \BAGArt\TelegramBotAntispam\Processors\AntispamMessageProcessor::class,
        \BAGArt\TelegramBotAntispam\Processors\AntispamReportCommand::class,
        \BAGArt\TelegramBotAntispam\Processors\AntispamStatusCommand::class,
        \BAGArt\TelegramBotAntispam\Processors\AppealCommand::class,
        \BAGArt\TelegramBotAntispam\Processors\CaptchaCallbackProcessor::class,
        \BAGArt\TelegramBotAntispam\Processors\CaptchaJoinProcessor::class,
    ];

    public function testEveryProcessorResolvesItsModuleId(): void
    {
        foreach (self::PROCESSORS as $processor) {
            self::assertTrue(is_subclass_of($processor, TgModuleProcessorContract::class), "{$processor} must implement the module processor contract");
            self::assertSame('antispam', $processor::moduleId(), "{$processor} must report the antispam module id");
        }
    }
}
