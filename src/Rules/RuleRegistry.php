<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Rules;

/**
 * Built-in rule catalog. Rules are stateless singletons — safe to share.
 *
 * @implements \IteratorAggregate<int, AntiSpamRule>
 */
final class RuleRegistry implements \IteratorAggregate
{
    /** Group id → default score cap (advertising 80 / flood 100 / behavior 100). */
    public const array GROUPS = [
        'advertising' => 80,
        'flood' => 100,
    ];

    /** @var list<AntiSpamRule> */
    private array $rules;

    public function __construct(?MessageRateRule $messageRateRule = null)
    {
        $this->rules = [
            new Content\AdvertisingRule(),
            new Content\PhoneEmailRule(),
            new Content\LinkFloodRule(),
            new Content\MentionFloodRule(),
            $messageRateRule ?? new Behavior\MessageRateRule(),
            new Behavior\RepeatedTextRule(),
            new Behavior\RepeatedMediaRule(),
            new Behavior\RepeatedStickerRule(),
            new Behavior\RepeatedEmojiRule(),
            new Behavior\CharacterFloodRule(),
            new Behavior\OversizedMessageRule(),
            new Behavior\ForwardFloodRule(),
            new Behavior\StickerEmojiFloodRule(),
            new Behavior\MediaFloodRule(),
            new Behavior\ActivityFloodRule(),
        ];
    }

    public function byId(string $ruleId): ?AntiSpamRule
    {
        foreach ($this->rules as $rule) {
            if ($rule->id() === $ruleId || str_starts_with($ruleId, $rule->id().'.')) {
                return $rule;
            }
        }

        return null;
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->rules);
    }
}
