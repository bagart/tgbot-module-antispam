<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Rules\Behavior;

use BAGArt\TelegramBotAntispam\Domain\AntiSpamDetection;
use BAGArt\TelegramBotAntispam\Domain\AntispamMessageContext;
use BAGArt\TelegramBotAntispam\Domain\DetectionSeverity;
use BAGArt\TelegramBotAntispam\Domain\EvaluationPlan;
use BAGArt\TelegramBotAntispam\Domain\RuleRequirements;
use BAGArt\TelegramBotAntispam\Rules\AntiSpamRule;
use BAGArt\TelegramBotAntispam\Rules\DetectionDefaults;

/**
 * Message rate over FOUR windows in one class. Detections differ by rule id
 * (flood.burst / flood.short / flood.medium / flood.long) so a single series
 * produces one detection — the strongest applicable window, never three redundant ones.
 */
final class MessageRateRule extends AntiSpamRule
{
    public const string ID = 'flood.rate';

    /** @var list<array{window: string, severity: string, score: int}> strongest first */
    private const array WINDOWS = [
        ['window' => 'burst', 'severity' => 'high', 'score' => 30],
        ['window' => 'short', 'severity' => 'medium', 'score' => 40],
        ['window' => 'medium', 'severity' => 'medium', 'score' => 50],
        ['window' => 'long', 'severity' => 'low', 'score' => 30],
    ];

    public function id(): string
    {
        return self::ID;
    }

    public function group(): string
    {
        return 'flood';
    }

    public function requirements(): RuleRequirements
    {
        return new RuleRequirements(counters: ['messages']);
    }

    public function check(AntispamMessageContext $context, EvaluationPlan $plan): ?AntiSpamDetection
    {
        foreach (self::WINDOWS as $level) {
            $ruleId = self::ID.'.'.$level['window'];
            if (! $plan->isEnabled($ruleId)) {
                continue;
            }

            $count = match ($level['window']) {
                'burst' => $context->behavior->messages5s,
                'short' => $context->behavior->messages30s,
                'medium' => $context->behavior->messages5m,
                'long' => $context->behavior->messages1h,
            };

            $limit = $plan->floodWindows[$level['window']] ?? 0;
            if ($limit > 0 && $count >= $limit) {
                return $this->detection(
                    $plan,
                    $level['score'],
                    new DetectionDefaults(
                        $level['score'],
                        DetectionSeverity::from($level['severity']),
                    ),
                    "Message rate: {$count} msgs in {$plan->windowSeconds[$level['window']]}s >= {$limit}",
                    ['window' => $level['window'], 'count' => $count, 'limit' => $limit],
                    ruleIdOverride: $ruleId,
                );
            }
        }

        return null;
    }
}
