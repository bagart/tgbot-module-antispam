<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Tests\Support;

use BAGArt\TelegramBotAntispam\Domain\AntispamMessageContext;
use BAGArt\TelegramBotAntispam\Domain\BehaviorContext;
use BAGArt\TelegramBotAntispam\Domain\ChatContext;
use BAGArt\TelegramBotAntispam\Domain\EvaluationPlan;
use BAGArt\TelegramBotAntispam\Domain\MessageData;
use BAGArt\TelegramBotAntispam\Domain\RuleGroup;
use BAGArt\TelegramBotAntispam\Domain\SeverityActionMap;
use BAGArt\TelegramBotAntispam\Domain\UserContext;
use BAGArt\TelegramBotAntispam\Engine\PolicyEvaluator;
use BAGArt\TelegramBotAntispam\Engine\RuleEngine;
use BAGArt\TelegramBotAntispam\Engine\VerdictAggregator;
use BAGArt\TelegramBotAntispam\Rules\RuleRegistry;

/** Test fixture builders for the pure engine layer. */
final class Fixtures
{
    public static function context(
        ?string $text = 'hello world',
        ?BehaviorContext $behavior = null,
        array $messageOverrides = [],
    ): AntispamMessageContext {
        $message = new MessageData(...array_replace([
            'messageId' => 10,
            'date' => new \DateTimeImmutable('@1700000000'),
            'text' => $text,
            'entities' => null,
            'hasMedia' => false,
            'mediaKind' => null,
            'mediaFileId' => null,
            'hasSticker' => false,
            'stickerEmoji' => null,
            'caption' => null,
            'isForwarded' => false,
            'isReply' => false,
            'length' => mb_strlen((string) $text),
        ], $messageOverrides));

        return new AntispamMessageContext(
            user: new UserContext(userId: 42, username: 'tester', isBot: false),
            chat: new ChatContext(chatId: 100, type: 'group'),
            message: $message,
            behavior: $behavior ?? new BehaviorContext(),
        );
    }

    public static function plan(array $overrides = []): EvaluationPlan
    {
        return new EvaluationPlan(
            policyVersion: 'antispam.policy.v1',
            rulesetVersion: 'test',
            warnScore: $overrides['warnScore'] ?? 40,
            restrictScore: $overrides['restrictScore'] ?? 80,
            banScore: $overrides['banScore'] ?? 150,
            globalCap: $overrides['globalCap'] ?? 200,
            groupCaps: $overrides['groupCaps'] ?? [
                new RuleGroup('advertising', 80),
                new RuleGroup('flood', 100),
            ],
            severityActions: $overrides['severityActions'] ?? new SeverityActionMap(),
            enabledRules: $overrides['enabledRules'] ?? [],
            floodWindows: $overrides['floodWindows'] ?? ['burst' => 5, 'short' => 15, 'medium' => 40, 'long' => 200],
        );
    }

    public static function engine(): RuleEngine
    {
        return new RuleEngine(iterator_to_array(new RuleRegistry()));
    }

    public static function evaluator(): \BAGArt\TelegramBotAntispam\Engine\AntispamEvaluator
    {
        return new \BAGArt\TelegramBotAntispam\Engine\AntispamEvaluator(
            self::engine(),
            new VerdictAggregator(),
            new PolicyEvaluator(),
        );
    }
}
