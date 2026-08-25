import { Head } from '@inertiajs/react';
import AntispamAnalyticsController from '@/actions/BAGArt/TelegramBotAntispam/Http/Controllers/AntispamAnalyticsController';
import Heading from '@/components/heading';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Anti-Spam Analytics',
        href: AntispamAnalyticsController.index(),
    },
];

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

interface TopRule {
    ruleId: string;
    count: number;
}

interface GroupContribution {
    groupId: string;
    violations: number;
    detections: number;
}

interface ChatRankingRow {
    botId: string;
    chatId: number;
    violations: number;
}

function heatColor(count: number, max: number): string {
    if (count === 0) {
        return 'bg-muted';
    }

    const intensity = Math.min(1, count / Math.max(max, 1));

    return `bg-red-500/${Math.max(20, Math.round(intensity * 100))}`;
}

function BarList({
    rows,
}: {
    rows: Array<{ label: string; value: number }>;
}) {
    const max = Math.max(1, ...rows.map((row) => row.value));

    if (rows.length === 0) {
        return <p className="text-sm text-muted-foreground">No data for the selected window.</p>;
    }

    return (
        <div className="flex flex-col gap-2">
            {rows.map((row) => {
                const value = row.value;

                return (
                    <div key={row.label} className="flex items-center gap-2">
                        <span className="w-48 truncate text-sm" title={row.label}>
                            {row.label}
                        </span>
                        <div className="h-3 flex-1 overflow-hidden rounded bg-muted">
                            <div
                                className="h-full rounded bg-red-500"
                                style={{ width: `${Math.round((value / max) * 100)}%` }}
                            />
                        </div>
                        <span className="w-10 text-right text-sm tabular-nums">{value}</span>
                    </div>
                );
            })}
        </div>
    );
}

export default function AntispamAnalytics({
    heatmap,
    topRules,
    groupContribution,
    chatRanking,
}: {
    heatmap: number[][];
    topRules: TopRule[];
    groupContribution: GroupContribution[];
    chatRanking: ChatRankingRow[];
}) {
    const maxCell = Math.max(1, ...heatmap.flat());

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Anti-Spam Analytics" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <Heading
                    title="Anti-Spam analytics"
                    description="Violation patterns over the last 30 days."
                />

                <div className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <h3 className="mb-3 text-base font-medium">Violations by weekday × hour</h3>
                    <div className="overflow-x-auto">
                        <table className="border-separate border-spacing-px">
                            <thead>
                                <tr>
                                    <th />
                                    {Array.from({ length: 24 }, (_, hour) => (
                                        <th
                                            key={hour}
                                            className="w-5 pb-1 text-[9px] font-normal text-muted-foreground"
                                        >
                                            {hour % 3 === 0 ? hour : ''}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {heatmap.map((hours, weekday) => (
                                    <tr key={weekday}>
                                        <td className="pr-2 text-xs text-muted-foreground">
                                            {WEEKDAYS[weekday]}
                                        </td>
                                        {hours.map((count, hour) => (
                                            <td key={hour}>
                                                <div
                                                    className={`size-4 rounded-sm ${heatColor(count, maxCell)}`}
                                                    title={`${WEEKDAYS[weekday]} ${hour}:00 — ${count}`}
                                                />
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <div className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <h3 className="mb-3 text-base font-medium">Top matched rules</h3>
                        <BarList
                            rows={topRules.map((rule) => ({
                                label: rule.ruleId,
                                value: rule.count,
                            }))}
                        />
                    </div>

                    <div className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <h3 className="mb-3 text-base font-medium">Group contribution</h3>
                        <BarList
                            rows={groupContribution.map((row) => ({
                                label: row.groupId,
                                value: row.violations,
                            }))}
                        />
                    </div>
                </div>

                <div className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <h3 className="mb-3 text-base font-medium">Chats by violations</h3>
                    {chatRanking.length === 0 ? (
                        <p className="text-sm text-muted-foreground">No violations recorded.</p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-left text-muted-foreground">
                                    <th className="pb-2">Bot</th>
                                    <th className="pb-2">Chat</th>
                                    <th className="pb-2 text-right">Violations</th>
                                </tr>
                            </thead>
                            <tbody>
                                {chatRanking.map((row) => (
                                    <tr key={`${row.botId}:${row.chatId}`} className="border-t">
                                        <td className="py-1.5">{row.botId}</td>
                                        <td className="py-1.5">{row.chatId}</td>
                                        <td className="py-1.5 text-right tabular-nums">
                                            {row.violations}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
