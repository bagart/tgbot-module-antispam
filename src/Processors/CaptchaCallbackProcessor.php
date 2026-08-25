<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Processors;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Processing\Processors\TgModuleProcessorContract;
use BAGArt\TelegramBot\Contracts\TgApi\TgApiTypeDTOContract;
use BAGArt\TelegramBot\Processing\BotProcessorContext;
use BAGArt\TelegramBot\Processing\ErrorHandling\ProcessorErrorContext;
use BAGArt\TelegramBot\TgApi\Types\DTO\CallbackQueryTypeDTO;
use BAGArt\TelegramBotAntispam\Captcha\CaptchaService;

/** Routes "antispam:captcha:*" inline callbacks into the CAPTCHA flow. */
final class CaptchaCallbackProcessor implements TgModuleProcessorContract
{
    public function __construct(
        private readonly CaptchaService $captcha,
    ) {
    }

    public static function moduleId(): string
    {
        return AntispamPipeline::MODULE_ID;
    }

    public static function build(BotProcessorContext $context): self
    {
        return new self(app(CaptchaService::class));
    }

    public function support(
        TgApiTypeDTOContract $dto,
        TgBotConfig $botConfig,
        ?string $action = null,
    ): bool {
        return $dto instanceof CallbackQueryTypeDTO
            && str_starts_with((string) ($dto->data ?? ''), CaptchaService::CALLBACK_PREFIX);
    }

    public function isStrictOrdered(
        TgApiTypeDTOContract $dto,
        TgBotConfig $botConfig,
        ?string $action = null,
    ): bool {
        return false;
    }

    public function process(
        TgApiTypeDTOContract $dto,
        TgBotConfig $botConfig,
        ?string $action = null,
        ?TgApiTypeDTOContract $updateDto = null,
    ): void {
        assert($dto instanceof CallbackQueryTypeDTO);

        $this->captcha->handleCallback($dto, $botConfig);
    }

    public function onException(ProcessorErrorContext $context): void
    {
    }
}
