<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Captcha;

use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;
use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Modules\ModuleSettingsContract;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\TgApi\Methods\DTO\AnswerCallbackQueryMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\DTO\BanChatMemberMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\DTO\RestrictChatMemberMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\DTO\UnbanChatMemberMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\CallbackQueryTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatMemberUpdatedTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatPermissionsTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\InlineKeyboardButtonTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\InlineKeyboardMarkupTypeDTO;
use BAGArt\TelegramBotAntispam\AntispamPipeline;
use BAGArt\TelegramBotAntispam\UserList\UserListManager;
use Throwable;

/**
 * CAPTCHA challenge flow (todo.antispam.md P3.6).
 *
 * Triggers: new joiner (ChatMemberUpdated) or user crossing the soft
 * threshold (warn verdict). The challenged user is restricted meanwhile;
 * correct click lifts the restriction + adds a short whitelist entry,
 * wrong click / expired challenge → ban or kick per settings.
 *
 * Failure policy: any storage/telegram error is logged and swallowed —
 * CAPTCHA never blocks message processing (fail-open like enforcement).
 */
final readonly class CaptchaService
{
    public const string CALLBACK_PREFIX = 'antispam:captcha:';

    public function __construct(
        private CaptchaStore $store,
        private UserListManager $lists,
        private TgSenderContract $sender,
        private ModuleSettingsContract $settings,
        private ASKLogWrapper $logger,
        /** @var list<int> */
        private array $excludeUserIds = [],
    ) {
    }

    public function handleJoin(ChatMemberUpdatedTypeDTO $dto, TgBotConfig $botConfig): void
    {
        $member = $dto->newChatMember;
        $isBot = isset($member->user) && $member->user->isBot === true;
        // The lib does not implement the ChatMember* oneOf contract yet
        // (DtoGenerator TODO): hydrated members can be property-less
        // placeholders. Missing facts → skip silently instead of crashing.
        if (! isset($member->user) || $isBot) {
            return;
        }

        try {
            $this->challenge(
                $botConfig,
                (int) $dto->chat->id,
                (int) $member->user->id,
            );
        } catch (Throwable $e) {
            $this->logFailure('join', $botConfig->botId, (int) $dto->chat->id, (int) $member->user->id, $e);
        }
    }

    /**
     * Soft-threshold trigger: called by the pipeline when the verdict action
     * is warn. No-op unless captcha is enabled for the chat.
     */
    public function challengeUser(string $botId, int $chatId, int $userId, TgBotConfig $botConfig): void
    {
        try {
            $this->challenge($botConfig, $chatId, $userId);
        } catch (Throwable $e) {
            $this->logFailure('threshold', $botId, $chatId, $userId, $e);
        }
    }

    public function handleCallback(CallbackQueryTypeDTO $dto, TgBotConfig $botConfig): void
    {
        $route = self::decode((string) ($dto->data ?? ''));
        if ($route === null) {
            $this->answer($botConfig, $dto, 'Unknown challenge.');

            return;
        }

        [$chatId, $userId, $token, $accepted] = $route;

        // Only the challenged user may answer their own challenge.
        if ((int) $dto->from->id !== $userId) {
            $this->answer($botConfig, $dto);

            return;
        }

        $config = $this->captchaSettings($botConfig->botId, $chatId);
        $consumed = $this->store->consume($botConfig->botId, $chatId, $userId, $token);

        // Unknown/expired token = timed-out challenge → fail path per settings.
        if (! $consumed) {
            $this->answer($botConfig, $dto, 'Challenge expired.');
            $this->applyFailOutcome($botConfig, $chatId, $userId, $config);

            return;
        }

        if ($accepted) {
            $this->passChallenge($botConfig, $chatId, $userId, $config);
            $this->answer($botConfig, $dto, '✅ Verified — welcome!');

            return;
        }

        $this->answer($botConfig, $dto, '❌ Verification failed.');
        $this->applyFailOutcome($botConfig, $chatId, $userId, $config);
    }

    /**
     * Parses "antispam:captcha:{chatId}:{userId}:{token}:{ok|no}".
     *
     * @return array{int, int, string, bool}|null [chatId, userId, token, accepted]
     */
    public static function decode(string $data): ?array
    {
        if (! str_starts_with($data, self::CALLBACK_PREFIX)) {
            return null;
        }

        $parts = explode(':', substr($data, strlen(self::CALLBACK_PREFIX)));
        if (count($parts) !== 4 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            return null;
        }

        $choice = $parts[3];

        return [
            (int) $parts[0],
            (int) $parts[1],
            $parts[2],
            $choice === 'ok',
        ];
    }

    /** @return list<InlineKeyboardButtonTypeDTO> */
    public static function buttons(int $chatId, int $userId, string $token): array
    {
        $base = self::CALLBACK_PREFIX."{$chatId}:{$userId}:{$token}:";

        return [
            new InlineKeyboardButtonTypeDTO(text: "✅ I'm human", callbackData: $base.'ok'),
            new InlineKeyboardButtonTypeDTO(text: '🤖 I am a bot', callbackData: $base.'no'),
        ];
    }

    private function challenge(TgBotConfig $botConfig, int $chatId, int $userId): void
    {
        $config = $this->captchaSettings($botConfig->botId, $chatId);
        if (! $config->enabled) {
            return;
        }

        if ($this->lists->isWhitelisted($botConfig->botId, $chatId, $userId)
            || in_array($userId, $this->excludeUserIds, true)) {
            return;
        }

        // Idempotent: a pending challenge is reused, never duplicated.
        [$token, $created] = $this->store->issue($botConfig->botId, $chatId, $userId, $config->ttlSeconds);
        if (! $created) {
            return;
        }

        $this->restrict($botConfig, $chatId, $userId, $config->ttlSeconds);

        $this->sender->send($botConfig, new SendMessageMethodDTO(
            chatId: (string) $chatId,
            text: sprintf(
                "👋 Welcome! Please confirm you are human within %d minutes to keep access to this chat.",
                (int) ceil($config->ttlSeconds / 60),
            ),
            replyMarkup: new InlineKeyboardMarkupTypeDTO(
                inlineKeyboard: [self::buttons($chatId, $userId, $token)],
            ),
        ));
    }

    /**
     * issue() reuses an existing pending token — a second trigger for the
     * same user must not re-restrict/re-send.
     */
    private function passChallenge(
        TgBotConfig $botConfig,
        int $chatId,
        int $userId,
        CaptchaSettings $config,
    ): void {
        // Lift restriction: grant standard send permissions back.
        $this->sender->send($botConfig, new RestrictChatMemberMethodDTO(
            chatId: (string) $chatId,
            userId: $userId,
            permissions: new ChatPermissionsTypeDTO(
                canSendMessages: true,
                canSendAudios: true,
                canSendDocuments: true,
                canSendPhotos: true,
                canSendVideos: true,
                canSendVideoNotes: true,
                canSendVoiceNotes: true,
                canSendPolls: true,
                canSendOtherMessages: true,
                canAddWebPagePreviews: true,
            ),
        ));

        $this->sender->send($botConfig, new SendMessageMethodDTO(
            chatId: (string) $chatId,
            text: '✅ Verification passed — welcome!',
        ));

        $this->lists->addWhitelistEntry(
            botId: $botConfig->botId,
            chatId: $chatId,
            userId: $userId,
            reason: 'antispam:captcha',
            expiresAt: now()->addSeconds($config->whitelistSeconds),
        );
    }

    /**
     * Kick = ban + immediate unban: the user is removed but may rejoin
     * (and face a fresh challenge).
     */
    private function applyFailOutcome(
        TgBotConfig $botConfig,
        int $chatId,
        int $userId,
        CaptchaSettings $config,
    ): void {
        $this->sender->send($botConfig, new BanChatMemberMethodDTO(
            chatId: (string) $chatId,
            userId: $userId,
        ));

        if ($config->onFail === CaptchaSettings::FAIL_KICK) {
            $this->sender->send($botConfig, new UnbanChatMemberMethodDTO(
                chatId: (string) $chatId,
                userId: $userId,
                onlyIfBanned: true,
            ));
        }
    }

    private function restrict(TgBotConfig $botConfig, int $chatId, int $userId, int $ttlSeconds): void
    {
        $this->sender->send($botConfig, new RestrictChatMemberMethodDTO(
            chatId: (string) $chatId,
            userId: $userId,
            permissions: new ChatPermissionsTypeDTO(), // all null = fully muted
            untilDate: time() + $ttlSeconds + 60,      // auto-expire safety net
        ));
    }

    private function answer(TgBotConfig $botConfig, CallbackQueryTypeDTO $dto, ?string $text = null): void
    {
        $this->sender->send($botConfig, new AnswerCallbackQueryMethodDTO(
            callbackQueryId: $dto->id,
            text: $text,
        ));
    }

    /** @return CaptchaSettings */
    private function captchaSettings(string $botId, int $chatId): CaptchaSettings
    {
        try {
            $moduleSettings = $this->settings->settingsFor(AntispamPipeline::MODULE_ID, $botId, $chatId);
        } catch (Throwable) {
            return new CaptchaSettings();
        }

        return CaptchaSettings::fromSettings($moduleSettings);
    }

    private function logFailure(string $stage, string $botId, int $chatId, int $userId, Throwable $e): void
    {
        $this->logger?->warning('antispam: captcha '.$stage.' failed', [
            'botId' => $botId,
            'chatId' => $chatId,
            'userId' => $userId,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
