<?php

namespace BAGArt\TelegramBotAntispam\Http\Controllers;

use BAGArt\TelegramBotAntispam\Models\AntispamStat;
use BAGArt\TelegramBotAntispam\Models\AntispamViolation;
use Inertia\Inertia;
use Inertia\Response;

class AntispamAnalyticsController
{
    private const int DEFAULT_DAYS = 30;

    public function index(): Response
    {
        return Inertia::render('antispam/analytics', [
            'heatmap' => self::heatmap(self::DEFAULT_DAYS),
            'topRules' => self::topRules(self::DEFAULT_DAYS, 10),
            'groupContribution' => self::groupContribution(self::DEFAULT_DAYS),
            'chatRanking' => self::chatRanking(self::DEFAULT_DAYS, 10),
        ]);
    }

    /**
     * Violations by weekday × hour (0..23). Rows are fetched for the window and
     * bucketed in PHP — keeps the query portable across sqlite/pgsql.
     *
     * @return list<list<int>> 7 rows (Mon..Sun) of 24 hourly counts
     */
    public static function heatmap(int $days = self::DEFAULT_DAYS): array
    {
        $grid = array_fill(0, 7, array_fill(0, 24, 0));

        AntispamViolation::query()
            ->select('created_at')
            ->where('created_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->chunk(500, function ($rows) use (&$grid): void {
                foreach ($rows as $row) {
                    $weekday = ((int) $row->created_at->format('N')) - 1; // Mon=0
                    $hour = (int) $row->created_at->format('G');
                    $grid[$weekday][$hour]++;
                }
            });

        return $grid;
    }

    /**
     * Most frequent matched rule ids within the window.
     *
     * @return list<array{ruleId: string, count: int}>
     */
    public static function topRules(int $days = self::DEFAULT_DAYS, int $limit = 10): array
    {
        /** @var array<string, int> $counts */
        $counts = [];

        AntispamViolation::query()
            ->select('matched_rules')
            ->where('created_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->chunk(500, function ($rows) use (&$counts): void {
                foreach ($rows as $row) {
                    foreach ((array) $row->matched_rules as $rule) {
                        $ruleId = (string) ($rule['ruleId'] ?? '');
                        if ($ruleId !== '') {
                            $counts[$ruleId] = ($counts[$ruleId] ?? 0) + 1;
                        }
                    }
                }
            });

        arsort($counts);

        return collect($counts)
            ->take($limit)
            ->map(fn (int $count, string $ruleId): array => ['ruleId' => $ruleId, 'count' => $count])
            ->values()
            ->all();
    }

    /**
     * Violations/detections per rule group from the daily stats rollup.
     *
     * @return list<array{groupId: string, violations: int, detections: int}>
     */
    public static function groupContribution(int $days = self::DEFAULT_DAYS): array
    {
        return AntispamStat::query()
            ->where('stat_date', '>=', today()->subDays($days - 1))
            ->whereNotNull('group_id')
            ->groupBy('group_id')
            ->selectRaw('group_id, coalesce(sum(violations), 0) as violations, coalesce(sum(detections), 0) as detections')
            ->orderByDesc('violations')
            ->get()
            ->map(fn (AntispamStat $stat): array => [
                'groupId' => (string) $stat->group_id,
                'violations' => (int) $stat->violations,
                'detections' => (int) $stat->detections,
            ])
            ->all();
    }

    /**
     * Chats ranked by violation count within the window.
     *
     * @return list<array{botId: string, chatId: int, violations: int}>
     */
    public static function chatRanking(int $days = self::DEFAULT_DAYS, int $limit = 10): array
    {
        return AntispamViolation::query()
            ->where('created_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->groupBy('bot_id', 'chat_id')
            ->selectRaw('bot_id, chat_id, count(*) as violations')
            ->orderByDesc('violations')
            ->limit($limit)
            ->get()
            ->map(fn (AntispamViolation $violation): array => [
                'botId' => (string) $violation->bot_id,
                'chatId' => (int) $violation->chat_id,
                'violations' => (int) $violation->violations,
            ])
            ->all();
    }
}
