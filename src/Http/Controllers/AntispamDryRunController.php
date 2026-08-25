<?php

namespace BAGArt\TelegramBotAntispam\Http\Controllers;

use BAGArt\TelegramBotAntispam\Http\Controllers\Support\AntispamEffectivePlan;
use BAGArt\TelegramBotAntispam\Domain\AntispamMessageContext;
use BAGArt\TelegramBotAntispam\Domain\BehaviorContext;
use BAGArt\TelegramBotAntispam\Domain\ChatContext;
use BAGArt\TelegramBotAntispam\Domain\MessageData;
use BAGArt\TelegramBotAntispam\Domain\UserContext;
use BAGArt\TelegramBotAntispam\DryRun\DryRunExecutor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AntispamDryRunController
{
    public function __construct(
        private readonly AntispamEffectivePlan $effectivePlan,
        private readonly DryRunExecutor $dryRun,
    ) {
    }

    /** Evaluates one text through the real compiled plan — zero side effects. */
    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bot_id' => ['required', 'string', 'max:20'],
            'chat_id' => ['required', 'integer'],
            'text' => ['required', 'string', 'max:8000'],
        ]);

        $plan = $this->effectivePlan->plan($validated['bot_id'], (int) $validated['chat_id']);
        $report = $this->dryRun->run($this->context($validated['text']), $plan);

        return response()->json([
            'policyVersion' => $report->policyVersion,
            'rulesetVersion' => $plan->rulesetVersion,
            'matchedRules' => $report->matchedRules,
            'groupBreakdown' => $report->groupBreakdown,
            'score' => $report->score,
            'globalCap' => $report->globalCap,
            'verdict' => [
                'action' => $report->verdict->action->value,
                'score' => $report->verdict->score,
                'reason' => $report->verdict->reason,
            ],
            'thresholds' => $report->verdict->thresholds,
        ]);
    }

    private function context(string $text): AntispamMessageContext
    {
        return new AntispamMessageContext(
            user: new UserContext(userId: 1, username: null, isBot: false),
            chat: new ChatContext(chatId: 1, type: 'supergroup'),
            message: new MessageData(
                messageId: 0,
                date: new \DateTimeImmutable(),
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
            ),
            behavior: new BehaviorContext(),
        );
    }
}
