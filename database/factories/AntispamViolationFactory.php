<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotAntispam\Database\Factories;

use BAGArt\TelegramBotAntispam\Models\AntispamViolation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AntispamViolation> */
class AntispamViolationFactory extends Factory
{
    protected $model = AntispamViolation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'bot_id' => 'bot_a',
            'chat_id' => 100,
            'user_id' => 42,
            'message_id' => $this->faker->unique()->numberBetween(1, 100000),
            'message_snapshot' => ['text' => 'spam text'],
            'matched_rules' => [
                ['ruleId' => 'flood.rate.burst', 'score' => 30, 'severity' => 'high', 'kind' => 'soft', 'group' => 'flood', 'reason' => 'rate'],
            ],
            'group_breakdown' => ['flood' => ['contribution' => 30, 'cap' => 100]],
            'risk_context' => null,
            'evaluation_snapshot' => [
                'policyVersion' => 'antispam.policy.v1',
                'riskVersion' => 'antispam.risk.v1',
                'rulesetVersion' => 'test',
                'score' => 30,
            ],
            'score' => 30,
            'verdict' => ['action' => 'delete', 'policyVersion' => 'antispam.policy.v1'],
            'enforcement_action' => 'delete',
            'status' => AntispamViolation::STATUS_PENDING,
        ];
    }

    public function forScope(string $botId, int $chatId, int $userId): static
    {
        return $this->state(fn (): array => [
            'bot_id' => $botId,
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }
}
