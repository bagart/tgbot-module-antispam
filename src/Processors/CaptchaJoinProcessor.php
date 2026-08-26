<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Processors;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Processing\Processors\TgModuleProcessorContract;
use BAGArt\TelegramBot\Contracts\TgApi\TgApiTypeDTOContract;
use BAGArt\TelegramBot\Processing\BotProcessorContext;
use BAGArt\TelegramBot\Processing\ErrorHandling\ProcessorErrorContext;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatMemberUpdatedTypeDTO;
use BAGArt\TelegramBotAntispam\Captcha\CaptchaService;

/**
 * CAPTCHA trigger for new joiners: old status left/kicked → new status
 * member/restricted (filtered in CaptchaService::handleJoin). my_chat_member
 * updates share the DTO class and are excluded via the $action discriminator.
 */
final class CaptchaJoinProcessor implements TgModuleProcessorContract
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
        return $dto instanceof ChatMemberUpdatedTypeDTO && $action === 'chat_member';
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
        assert($dto instanceof ChatMemberUpdatedTypeDTO);

        $this->captcha->handleJoin($dto, $botConfig);
    }

    public function onException(ProcessorErrorContext $context): void
    {
    }
}
