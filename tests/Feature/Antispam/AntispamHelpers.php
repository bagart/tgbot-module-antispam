<?php

declare(strict_types=1);

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\Contracts\TgApi\TgApiMethodDTOContract;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\MessageTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\UserTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\Enum\ChatPropTypeEnum;
use BAGArt\TelegramBotAntispam\AntispamPipeline;

/** Shared fixtures for the antispam feature tests. Pure functions only. */

function antispamMessage(int $chatId, int $userId, string $text, int $messageId = 10): MessageTypeDTO
{
    return new MessageTypeDTO(
        messageId: $messageId,
        date: time(),
        chat: new ChatTypeDTO(id: (string) $chatId, type: ChatPropTypeEnum::SUPERGROUP),
        from: new UserTypeDTO(id: (string) $userId, isBot: false, firstName: 'Spammer'),
        text: $text,
    );
}

function senderSpy(): TgSenderContract
{
    return new class () implements TgSenderContract {
        /** @var list<class-string> */
        public array $sent = [];

        /** @var list<TgApiMethodDTOContract> */
        public array $dtos = [];

        public function send(TgBotConfig $botConfig, TgApiMethodDTOContract $dto): void
        {
            $this->sent[] = $dto::class;
            $this->dtos[] = $dto;
        }
    };
}

/** Resolves the pipeline with the spy sender bound before the chain materializes. */
function pipelineWith(TgSenderContract $spy): AntispamPipeline
{
    app()->instance(TgSenderContract::class, $spy);

    return app(AntispamPipeline::class);
}
