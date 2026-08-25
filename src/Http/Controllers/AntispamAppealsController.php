<?php

namespace BAGArt\TelegramBotAntispam\Http\Controllers;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBotAntispam\Moderation\AntispamModerationService;
use BAGArt\TelegramBotAntispam\Models\AntispamAppeal;
use BAGArt\TelegramBotAntispam\Models\AntispamViolation;
use BAGArt\TelegramBotManagement\Models\TgBot;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AntispamAppealsController
{
    public function __construct(
        private readonly AntispamModerationService $moderation,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'bot_id' => ['nullable', 'string', 'max:20'],
            'user_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', Rule::in(AntispamAppeal::STATUSES)],
        ]);

        $query = AntispamAppeal::query()
            ->with('violation')
            ->when($filters['bot_id'] ?? null, fn (Builder $q, string $v) => $q->whereRelation('violation', 'bot_id', $v))
            ->when($filters['user_id'] ?? null, fn (Builder $q, int $v) => $q->where('user_id', $v))
            ->when($filters['status'] ?? null, fn (Builder $q, string $v) => $q->where('status', $v));

        return Inertia::render('antispam/appeals', [
            'appeals' => $query
                ->latest()
                ->paginate(20)
                ->withQueryString()
                ->through(fn (AntispamAppeal $appeal): array => [
                    'id' => (string) $appeal->id,
                    'userId' => (int) $appeal->user_id,
                    'message' => $appeal->message,
                    'status' => (string) $appeal->status,
                    'decidedBy' => $appeal->decided_by,
                    'decidedAt' => $appeal->decided_at?->toDateTimeString(),
                    'createdAt' => (string) $appeal->created_at,
                    'violation' => [
                        'id' => (string) $appeal->violation->id,
                        'botId' => (string) $appeal->violation->bot_id,
                        'chatId' => (int) $appeal->violation->chat_id,
                        'messageText' => (string) ($appeal->violation->message_snapshot['text'] ?? $appeal->violation->message_snapshot['caption'] ?? ''),
                        'matchedRules' => (array) $appeal->violation->matched_rules,
                        'score' => (int) $appeal->violation->score,
                        'enforcementAction' => (string) $appeal->violation->enforcement_action,
                        'status' => (string) $appeal->violation->status,
                    ],
                ]),
            'filters' => $filters,
            'bots' => TgBot::query()->orderBy('bot_id')->get(['bot_id']),
            'statuses' => AntispamAppeal::STATUSES,
        ]);
    }

    public function decide(Request $request, string $appealId): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
        ]);

        $appeal = AntispamAppeal::query()->with('violation')->find($appealId);
        if ($appeal === null) {
            throw new NotFoundHttpException("Appeal [{$appealId}] not found.");
        }

        /** @var AntispamViolation $violation */
        $violation = $appeal->violation;
        $bot = TgBot::query()->where('bot_id', $violation->bot_id)->first();
        if ($bot === null) {
            return response()->json(['error' => "Bot [{$violation->bot_id}] has no token on record."], 409);
        }

        $decided = $this->moderation->decideAppeal(
            appeal: $appeal,
            approve: $data['decision'] === 'approve',
            decidedBy: (string) $request->user()?->email,
            botConfig: new TgBotConfig(token: $bot->token, botId: (string) $bot->bot_id),
        );

        if (! $decided) {
            return response()->json(['error' => 'Appeal has already been decided.'], 409);
        }

        return response()->json([
            'appealId' => (string) $appeal->id,
            'status' => (string) $appeal->status,
            'violationStatus' => (string) $violation->fresh()->status,
        ]);
    }
}
