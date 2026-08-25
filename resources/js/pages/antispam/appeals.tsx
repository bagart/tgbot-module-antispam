import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AntispamAppealsController from '@/actions/BAGArt/TelegramBotAntispam/Http/Controllers/AntispamAppealsController';
import AntispamViolationsController from '@/actions/BAGArt/TelegramBotAntispam/Http/Controllers/AntispamViolationsController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Anti-Spam',
        href: AntispamAppealsController.index(),
    },
];

interface MatchedRule {
    ruleId: string;
    score: number;
    severity: string;
    kind: string;
    group: string;
    reason: string;
}

interface AppealRow {
    id: string;
    userId: number;
    message: string | null;
    status: string;
    decidedBy: string | null;
    decidedAt: string | null;
    createdAt: string;
    violation: {
        id: string;
        botId: string;
        chatId: number;
        messageText: string;
        matchedRules: MatchedRule[];
        score: number;
        enforcementAction: string;
        status: string;
    };
}

export default function AntispamAppeals({
    appeals,
    filters,
    bots,
    statuses,
}: {
    appeals: {
        data: AppealRow[];
        current_page: number;
        last_page: number;
    };
    filters: Partial<Record<'bot_id' | 'user_id' | 'status', string>>;
    bots: Array<{ bot_id: string }>;
    statuses: string[];
}) {
    const [filterState, setFilterState] = useState(filters);
    const [busyId, setBusyId] = useState<string | null>(null);

    const applyFilters = () => {
        router.get(AntispamAppealsController.index().url, filterState, {
            preserveState: true,
        });
    };

    const decide = (appeal: AppealRow, decision: 'approve' | 'reject') => {
        setBusyId(appeal.id);

        fetch(AntispamAppealsController.decide({ appeal: appeal.id }).url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({ decision }),
        })
            .then((response) => {
                if (!response.ok) {
                    return response
                        .json()
                        .then((body) => alert(body.error ?? 'Decision failed'));
                }

                router.reload({ only: ['appeals'] });
            })
            .finally(() => setBusyId(null));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Anti-Spam appeals" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <Heading
                    title="Appeals"
                    description="User appeals against sanctions. Approving lifts the sanction and overturns the violation."
                />

                <div className="flex flex-wrap items-center gap-3 text-sm">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={AntispamViolationsController.index()}>
                            ← Violations
                        </Link>
                    </Button>
                </div>

                <div className="flex flex-wrap items-end gap-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <div className="space-y-1.5">
                        <label className="text-sm">Bot</label>
                        <select
                            value={filterState.bot_id ?? ''}
                            onChange={(e) =>
                                setFilterState((f) => ({
                                    ...f,
                                    bot_id: e.target.value,
                                }))
                            }
                            className="w-32 rounded-md border bg-transparent px-3 py-2 text-sm"
                        >
                            <option value="">any</option>
                            {bots.map((bot) => (
                                <option key={bot.bot_id} value={bot.bot_id}>
                                    {bot.bot_id}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="space-y-1.5">
                        <label className="text-sm">Status</label>
                        <select
                            value={filterState.status ?? ''}
                            onChange={(e) =>
                                setFilterState((f) => ({
                                    ...f,
                                    status: e.target.value,
                                }))
                            }
                            className="w-32 rounded-md border bg-transparent px-3 py-2 text-sm"
                        >
                            <option value="">any</option>
                            {statuses.map((status) => (
                                <option key={status} value={status}>
                                    {status}
                                </option>
                            ))}
                        </select>
                    </div>
                    <Button onClick={applyFilters}>Apply</Button>
                </div>

                <div className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    {appeals.data.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No appeals match the filters.
                        </p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left text-muted-foreground">
                                    <th className="py-2 pr-4">When</th>
                                    <th className="py-2 pr-4">Scope</th>
                                    <th className="py-2 pr-4">Reason (user)</th>
                                    <th className="py-2 pr-4">Violation</th>
                                    <th className="py-2 pr-4">Sanction</th>
                                    <th className="py-2 pr-4">Appeal status</th>
                                    <th className="py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {appeals.data.map((appeal) => (
                                    <tr
                                        key={appeal.id}
                                        className="border-b align-top last:border-0"
                                    >
                                        <td className="py-2 pr-4 whitespace-nowrap">
                                            {appeal.createdAt.slice(0, 16)}
                                        </td>
                                        <td className="py-2 pr-4 whitespace-nowrap">
                                            {appeal.violation.botId}/
                                            {appeal.violation.chatId}/u
                                            {appeal.userId}
                                        </td>
                                        <td
                                            className="max-w-48 truncate py-2 pr-4"
                                            title={appeal.message ?? ''}
                                        >
                                            {appeal.message ||
                                                '(no reason given)'}
                                        </td>
                                        <td className="py-2 pr-4">
                                            <div className="flex flex-wrap gap-1">
                                                <Badge variant="outline">
                                                    {appeal.violation.score} pts
                                                </Badge>
                                                <span className="max-w-40 truncate text-xs text-muted-foreground">
                                                    {appeal.violation.matchedRules
                                                        .map(
                                                            (rule) =>
                                                                rule.ruleId,
                                                        )
                                                        .join(', ')}
                                                </span>
                                            </div>
                                        </td>
                                        <td className="py-2 pr-4">
                                            {appeal.violation.enforcementAction}
                                            <span className="ml-1 text-xs text-muted-foreground">
                                                ({appeal.violation.status})
                                            </span>
                                        </td>
                                        <td className="py-2 pr-4">
                                            {appeal.status}
                                            {appeal.decidedBy && (
                                                <span className="ml-1 block text-xs text-muted-foreground">
                                                    by {appeal.decidedBy} ·{' '}
                                                    {appeal.decidedAt?.slice(
                                                        0,
                                                        16,
                                                    )}
                                                </span>
                                            )}
                                        </td>
                                        <td className="py-2 text-right whitespace-nowrap">
                                            {appeal.status === 'pending' ? (
                                                <>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        disabled={
                                                            busyId === appeal.id
                                                        }
                                                        onClick={() =>
                                                            decide(
                                                                appeal,
                                                                'approve',
                                                            )
                                                        }
                                                    >
                                                        Approve
                                                    </Button>{' '}
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        disabled={
                                                            busyId === appeal.id
                                                        }
                                                        onClick={() =>
                                                            decide(
                                                                appeal,
                                                                'reject',
                                                            )
                                                        }
                                                    >
                                                        Reject
                                                    </Button>
                                                </>
                                            ) : (
                                                <span className="text-xs text-muted-foreground">
                                                    decided
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}

                    {appeals.last_page > 1 && (
                        <p className="mt-3 text-xs text-muted-foreground">
                            Page {appeals.current_page} of {appeals.last_page}
                        </p>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
