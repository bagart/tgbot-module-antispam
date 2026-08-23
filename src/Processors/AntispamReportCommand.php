<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Processors;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\Contracts\Processing\Processors\TgModuleProcessorContract;
use BAGArt\TelegramBot\Contracts\TgApi\TgApiTypeDTOContract;
use BAGArt\TelegramBot\Processing\BotProcessorContext;
use BAGArt\TelegramBot\Processing\ErrorHandling\ProcessorErrorContext;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\MessageTypeDTO;
use BAGArt\TelegramBotAntispam\AntispamPipeline;

/** /report (as a reply to a spam message) — counts manual reports per chat. */
final class AntispamReportCommand implements TgModuleProcessorContract
{
    public const string NAME = 'report';

    public function __construct(
        private readonly TgSenderContract $sender,
    ) {
    }

    public static function moduleId(): string
    {
        return AntispamPipeline::MODULE_ID;
    }

    public static function build(BotProcessorContext $context): self
    {
        return new self($context->tgSender);
    }

    public function support(
        TgApiTypeDTOContract $dto,
        TgBotConfig $botConfig,
        ?string $action = null,
    ): bool {
        return $dto instanceof MessageTypeDTO
            && $dto->text !== null
            && \BAGArt\TelegramBot\Modules\TgCommandRegistry::parseCommandName($dto->text) === self::NAME;
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
        assert($dto instanceof MessageTypeDTO);

        if ($dto->replyToMessage === null) {
            $this->sender->send($botConfig, new SendMessageMethodDTO(
                chatId: (string) $dto->chat->id,
                text: 'Reply to the spam message with /report.',
            ));

            return;
        }

        $this->sender->send($botConfig, new SendMessageMethodDTO(
            chatId: (string) $dto->chat->id,
            replyParameters: new \BAGArt\TelegramBot\TgApi\Types\DTO\ReplyParametersTypeDTO(
                messageId: $dto->messageId,
            ),
            text: '✅ Report accepted. Moderators will review it.',
        ));
    }

    public function onException(ProcessorErrorContext $context): void
    {
    }
}
