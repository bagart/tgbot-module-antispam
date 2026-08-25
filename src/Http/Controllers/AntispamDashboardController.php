<?php

namespace BAGArt\TelegramBotAntispam\Http\Controllers;

use BAGArt\TelegramBotAntispam\Models\AntispamRuleModel;
use BAGArt\TelegramBotAntispam\Models\AntispamStat;
use BAGArt\TelegramBotAntispam\Models\AntispamViolation;
use BAGArt\TelegramBotManagement\Models\TgBot;
use Inertia\Inertia;
use Inertia\Response;

class AntispamDashboardController
{
    public function index(): Response
    {
        $byStatus = AntispamViolation::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $today = AntispamStat::query()
            ->where('stat_date', today())
            ->selectRaw('coalesce(sum(detections), 0) as detections, coalesce(sum(violations), 0) as violations')
            ->first();

        return Inertia::render('antispam/dashboard', [
            'violationsByStatus' => [
                'pending' => (int) ($byStatus['pending'] ?? 0),
                'applied' => (int) ($byStatus['applied'] ?? 0),
                'overturned' => (int) ($byStatus['overturned'] ?? 0),
                'escalated' => (int) ($byStatus['escalated'] ?? 0),
            ],
            'today' => [
                'detections' => (int) $today->detections,
                'violations' => (int) $today->violations,
            ],
            'recentViolations' => AntispamViolation::query()
                ->latest()
                ->limit(8)
                ->get(['id', 'bot_id', 'chat_id', 'user_id', 'score', 'enforcement_action', 'status', 'created_at']),
            'activeRules' => AntispamRuleModel::query()->where('is_active', true)->count(),
            'bots' => TgBot::query()->orderBy('bot_id')->get(['bot_id']),
        ]);
    }
}
