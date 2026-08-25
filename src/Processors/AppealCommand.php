<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Processors;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\Contracts\Processing\Processors\TgModuleProcessorContract;
use BAGArt\TelegramBot\Contracts\TgApi\TgApiTypeDTOContract;
use BAGArt\TelegramBot\Modules\TgCommandRegistry;
use BAGArt\TelegramBot\Processing\BotProcessorContext;
use BAGArt\TelegramBot\Processing\ErrorHandling\ProcessorErrorContext;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\MessageTypeDTO;
use BAGArt\TelegramBotAntispam\AntispamPipeline;
use BAGArt\TelegramBotAntispam\Appeals\AppealFilingOutcome;
use BAGArt\TelegramBotAntispam\Appeals\AppealManager;
use Throwable;

/**
 * /appeal <reason> — files an appeal against the user's latest active
 * sanction (restrict/ban) in this chat.
 */
final class AppealCommand implements TgModuleProcessorContract
{
    public const string NAME = 'appeal';

    public function __construct(
        private readonly TgSenderContract $sender,
        private readonly AppealManager $appeals,
    ) {
    }

    public static function moduleId(): string
    {
        return AntispamPipeline::MODULE_ID;
    }

    public static function build(BotProcessorContext $context): self
    {
        return new self(
            sender: $context->tgSender,
            appeals: app(AppealManager::class),
        );
    }

    public function support(
        TgApiTypeDTOContract $dto,
        TgBotConfig $botConfig,
        ?string $action = null,
    ): bool {
        return $dto instanceof MessageTypeDTO
            && $dto->text !== null
            && TgCommandRegistry::parseCommandName($dto->text) === self::NAME;
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

        $chatId = (string) $dto->chat->id;
        $reason = trim(mb_substr((string) $dto->text, strlen(self::NAME) + 2));

        if ($reason === '') {
            $this->reply($botConfig, $chatId, 'Usage: /appeal <reason>');

            return;
        }

        try {
            $outcome = $this->appeals->file(
                botId: $botConfig->botId,
                chatId: (int) $chatId,
                userId: (int) ($dto->from?->id ?? 0),
                reason: mb_substr($reason, 0, 1000),
            );
        } catch (Throwable) {
            $this->reply($botConfig, $chatId, '⚠️ Appeals are temporarily unavailable. Try again later.');

            return;
        }

        $this->reply($botConfig, $chatId, match ($outcome) {
            AppealFilingOutcome::Created => '✅ Appeal filed. Moderators will review it soon.',
            AppealFilingOutcome::DuplicatePending => '⏳ You already have a pending appeal. Please wait for a decision.',
            AppealFilingOutcome::NoActiveSanction => 'ℹ️ You have no active sanction to appeal here.',
        });
    }

    private function reply(TgBotConfig $botConfig, string $chatId, string $text): void
    {
        $this->sender->send($botConfig, new SendMessageMethodDTO(chatId: $chatId, text: $text));
    }

    public function onException(ProcessorErrorContext $context): void
    {
    }
}
