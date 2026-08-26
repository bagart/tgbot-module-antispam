<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Processors;

use BAGArt\TelegramBot\TgApi\Types\DTO\MessageTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\Enum\ChatPropTypeEnum;
use BAGArt\TelegramBotAntispam\Domain\ChatContext;
use BAGArt\TelegramBotAntispam\Domain\MessageData;
use BAGArt\TelegramBotAntispam\Domain\UserContext;

/** Telegram DTO → normalized pure facts for the engine. */
final readonly class MessageNormalizer
{
    /**
     * @return array{UserContext, ChatContext, MessageData}|null null when facts are unusable (no sender)
     */
    public function normalize(MessageTypeDTO $dto): ?array
    {
        if ($dto->from === null || $dto->from->isBot === true) {
            return null;
        }

        $mediaKind = match (true) {
            $dto->photo !== null => 'photo',
            $dto->video !== null => 'video',
            $dto->document !== null => 'document',
            $dto->audio !== null => 'audio',
            $dto->animation !== null => 'animation',
            $dto->voice !== null => 'voice',
            $dto->videoNote !== null => 'video_note',
            default => null,
        };

        // file_unique_id is stable across resends — the true repeat-detection identity
        $mediaFileId = match (true) {
            $dto->photo !== null => $dto->photo === [] ? null : $dto->photo[count($dto->photo) - 1]->fileUniqueId,
            $dto->video !== null => $dto->video->fileUniqueId,
            $dto->document !== null => $dto->document->fileUniqueId,
            $dto->audio !== null => $dto->audio->fileUniqueId,
            $dto->animation !== null => $dto->animation->fileUniqueId,
            $dto->voice !== null => $dto->voice->fileUniqueId,
            $dto->videoNote !== null => $dto->videoNote->fileUniqueId,
            $dto->sticker !== null => $dto->sticker->fileUniqueId,
            default => null,
        };

        $mediaDurationSeconds = $dto->voice?->duration
            ?? $dto->videoNote?->duration
            ?? $dto->audio?->duration
            ?? $dto->video?->duration;

        $message = new MessageData(
            messageId: $dto->messageId,
            date: new \DateTimeImmutable('@'.$dto->date),
            text: $dto->text,
            entities: $this->entities($dto->entities),
            hasMedia: $mediaKind !== null,
            mediaKind: $mediaKind,
            mediaFileId: $mediaFileId,
            hasSticker: $dto->sticker !== null,
            stickerEmoji: $dto->sticker?->emoji,
            caption: $dto->caption,
            isForwarded: $dto->forwardOrigin !== null,
            isReply: $dto->replyToMessage !== null,
            length: mb_strlen($dto->text ?? $dto->caption ?? ''),
            mediaDurationSeconds: $mediaDurationSeconds,
        );

        $user = new UserContext(
            userId: (int) $dto->from->id,
            username: $dto->from->username,
            isBot: $dto->from->isBot === true,
            isPremium: $dto->from->isPremium,
        );

        $chat = new ChatContext(
            chatId: (int) $dto->chat->id,
            type: $dto->chat->type->value,
        );

        return [$user, $chat, $message];
    }

    public function isGroupChat(MessageTypeDTO $dto): bool
    {
        return in_array($dto->chat->type, [ChatPropTypeEnum::GROUP, ChatPropTypeEnum::SUPERGROUP], true);
    }

    /**
     * @param  array|null  $raw  raw entity arrays from the DTO
     * @return list<array{type: string, offset: int, length: int, url?: string, custom_emoji_id?: string}>|null
     */
    private function entities(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $out = [];
        foreach ($raw as $entity) {
            $item = [
                'type' => (string) ($entity['type'] ?? ''),
                'offset' => (int) ($entity['offset'] ?? 0),
                'length' => (int) ($entity['length'] ?? 0),
            ];
            if (isset($entity['url'])) {
                $item['url'] = (string) $entity['url'];
            }
            if (isset($entity['custom_emoji_id'])) {
                $item['custom_emoji_id'] = (string) $entity['custom_emoji_id'];
            }
            $out[] = $item;
        }

        return $out;
    }
}
