<?php

namespace BAGArt\TelegramBotAntispam\Http\Controllers;

use BAGArt\TelegramBotAntispam\Models\AntispamStat;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Validation\Rule;

class AntispamStatsController
{
    /**
     * Daily detections/violations aggregated per date (optionally per chat /
     * group) — the data source for the stats dashboard and exports.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function aggregate(int $days, ?string $botId = null, ?int $chatId = null): array
    {
        return AntispamStat::query()
            ->when($botId !== null, fn (Builder $q) => $q->where('bot_id', $botId))
            ->when($chatId !== null, fn (Builder $q) => $q->where('chat_id', $chatId))
            ->where('stat_date', '>=', today()->subDays($days - 1))
            ->selectRaw('stat_date, coalesce(sum(detections), 0) as detections, coalesce(sum(violations), 0) as violations')
            ->groupBy('stat_date')
            ->orderBy('stat_date')
            ->get()
            ->map(fn (AntispamStat $stat): array => [
                'date' => $stat->stat_date->toDateString(),
                'detections' => (int) $stat->detections,
                'violations' => (int) $stat->violations,
            ])
            ->all();
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'daily' => self::aggregate(14),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $format = $request->validate([
            'format' => ['nullable', Rule::in(['csv', 'json'])],
        ])['format'] ?? 'csv';

        $rows = self::aggregate(90);
        $filename = 'antispam-stats-'.now()->toDateString();

        if ($format === 'json') {
            return response()->streamDownload(function () use ($rows): void {
                echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }, "{$filename}.json", ['Content-Type' => 'application/json']);
        }

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['date', 'detections', 'violations']);
            foreach ($rows as $row) {
                fputcsv($out, [$row['date'], $row['detections'], $row['violations']]);
            }
            fclose($out);
        }, "{$filename}.csv", [
            'Content-Type' => 'text/csv',
        ]);
    }
}
