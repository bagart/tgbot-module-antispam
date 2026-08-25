<?php

namespace BAGArt\TelegramBotAntispam\Http\Controllers;

use BAGArt\TelegramBotAntispam\Http\Controllers\Support\AntispamEffectivePlan;
use BAGArt\TelegramBotAntispam\Models\AntispamViolation;
use BAGArt\TelegramBotAntispam\Replay\ReplayEvaluator;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AntispamReplayController
{
    public function __construct(
        private readonly AntispamEffectivePlan $effectivePlan,
        private readonly ReplayEvaluator $replay,
    ) {
    }

    /**
     * Compares the stored verdict against the current effective policy.
     */
    public function compare(string $violationId): JsonResponse
    {
        $violation = AntispamViolation::query()->find($violationId);
        if ($violation === null) {
            throw new NotFoundHttpException("Violation [{$violationId}] not found.");
        }

        $plan = $this->effectivePlan->plan((string) $violation->bot_id, (int) $violation->chat_id);
        $comparison = $this->replay->replay($violation, $plan);

        return response()->json([
            'violationId' => (string) $comparison->violationId,
            'oldAction' => $comparison->oldAction->value,
            'newAction' => $comparison->newAction->value,
            'oldScore' => $comparison->oldScore,
            'newScore' => $comparison->newVerdict->score,
            'reason' => $comparison->newVerdict->reason,
            'rulesetVersion' => $plan->rulesetVersion,
            'changed' => $comparison->oldAction !== $comparison->newAction,
        ]);
    }
}
