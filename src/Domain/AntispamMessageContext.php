<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Domain;

/**
 * Immutable, pure evaluation input: four independent value objects
 * (user / chat / message / behavior).
 */
final readonly class AntispamMessageContext
{
    public function __construct(
        public UserContext $user,
        public ChatContext $chat,
        public MessageData $message,
        public BehaviorContext $behavior,
    ) {
    }
}
