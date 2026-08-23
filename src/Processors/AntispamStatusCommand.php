<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Processors;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Modules\ModuleSettingsContract;
use BAGArt\TelegramBot\Contracts\Processing\Processors\TgModuleProcessorContract;
use BAGArt\TelegramBot\Contracts\TgApi\TgApiTypeDTOContract;
use BAGArt\TelegramBot\Processing\BotProcessorContext;
use BAGArt\TelegramBot\Processing\ErrorHandling\ProcessorErrorContext;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\MessageTypeDTO;
use BAGArt\TelegramBotAntispam\AntispamPipeline;
use BAGArt\TelegramBotAntispam\Domain\BehaviorContext;
use BAGArt\TelegramBotAntispam\Domain\MessageData;
use BAGArt\TelegramBotAntispam\Domain\UserContext;
use BAGArt\TelegramBotAntispam\Domain\ChatContext;
use BAGArt\TelegramBotAntispam\DryRun\DryRunExecutor;
use BAGArt\TelegramBotAntispam\Models\AntispamStat;
use Throwable;

/**
 * /antispam — status summary; "/antispam test <text>" — dry-run breakdown
 * of the given text as if it was sent by the invoking user.
 */
final class AntispamStatusCommand implements TgModuleProcessorContract
{
    public const string NAME = 'antispam';

    public function __construct(
        private readonly \BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract $sender,
        private readonly DryRunExecutor $dryRun,
        private readonly ModuleSettingsContract $settings,
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
            dryRun: app(DryRunExecutor::class),
            settings: app(ModuleSettingsContract::class),
        );
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

        $chatId = (string) $dto->chat->id;
        $payload = trim(mb_substr((string) $dto->text, strlen(self::NAME) + 2));

        if (str_starts_with($payload, 'test')) {
            $this->replyTest($dto, $botConfig, $chatId, trim(substr($payload, 4)));

            return;
        }

        $this->replyStatus($botConfig, $chatId);
    }

    private function replyStatus(TgBotConfig $botConfig, string $chatId): void
    {
        try {
            $violationsToday = AntispamStat::query()
                ->where('stat_date', now()->toDateString())
                ->where('chat_id', (int) $chatId)
                ->sum('violations');
            $detectionsToday = AntispamStat::query()
                ->where('stat_date', now()->toDateString())
                ->where('chat_id', (int) $chatId)
                ->sum('detections');
            $status = "🛡 Antispam active. Today: {$violationsToday} violations, {$detectionsToday} detections.";
        } catch (Throwable) {
            $status = '🛡 Antispam active.';
        }

        $this->sender->send($botConfig, new SendMessageMethodDTO(chatId: $chatId, text: $status));
    }

    private function replyTest(MessageTypeDTO $dto, TgBotConfig $botConfig, string $chatId, string $text): void
    {
        if ($dto->from === null || $text === '') {
            $this->sender->send($botConfig, new SendMessageMethodDTO(
                chatId: $chatId,
                text: 'Usage: /antispam test <message text>',
            ));

            return;
        }

        [$user, $chat] = $this->testFacts($dto);
        $message = new MessageData(
            messageId: $dto->messageId,
            date: new \DateTimeImmutable('@'.$dto->date),
            text: $text,
            entities: null,
            hasMedia: false,
            mediaKind: null,
            mediaFileId: null,
            hasSticker: false,
            stickerEmoji: null,
            caption: null,
            isForwarded: false,
            isReply: false,
            length: mb_strlen($text),
        );

        $context = new \BAGArt\TelegramBotAntispam\Domain\AntispamMessageContext(
            user: $user,
            chat: $chat,
            message: $message,
            behavior: new BehaviorContext(),
        );

        try {
            $moduleSettings = $this->settings->settingsFor(AntispamPipeline::MODULE_ID, $botConfig->botId, (int) $chatId);
        } catch (Throwable) {
            $moduleSettings = [];
        }

        $plan = app(\BAGArt\TelegramBotAntispam\Engine\PolicyCompiler::class)
            ->compile($botConfig->botId, (int) $chatId, $moduleSettings);
        $report = $this->dryRun->run($context, $plan);

        $this->sender->send($botConfig, new SendMessageMethodDTO(
            chatId: $chatId,
            text: "🧪 Dry-run:\n".implode("\n", $report->toLines()),
        ));
    }

    /** @return array{UserContext, ChatContext} */
    private function testFacts(MessageTypeDTO $dto): array
    {
        return [
            new UserContext(userId: (int) ($dto->from?->id ?? 0), username: $dto->from?->username, isBot: false),
            new ChatContext(chatId: (int) $dto->chat->id, type: $dto->chat->type->value),
        ];
    }

    public function onException(ProcessorErrorContext $context): void
    {
    }
}
