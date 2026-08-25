import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AntispamAppealsController from '@/actions/BAGArt/TelegramBotAntispam/Http/Controllers/AntispamAppealsController';
import AntispamDryRunController from '@/actions/BAGArt/TelegramBotAntispam/Http/Controllers/AntispamDryRunController';
import AntispamReplayController from '@/actions/BAGArt/TelegramBotAntispam/Http/Controllers/AntispamReplayController';
import AntispamViolationsController from '@/actions/BAGArt/TelegramBotAntispam/Http/Controllers/AntispamViolationsController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Anti-Spam',
        href: AntispamViolationsController.index(),
    },
];

type ModerationAction = 'apply' | 'overturn' | 'escalate';

const ACTION_CONFIRM: Record<ModerationAction, string> = {
    apply: 'Execute the enforcement action for this violation?',
    overturn: 'Overturn this violation and lift active sanctions?',
    escalate: 'Escalate this violation one step up the sanction ladder?',
};

function actionsFor(status: string): ModerationAction[] {
    if (status === 'pending') {
        return ['apply', 'overturn', 'escalate'];
    }

    return status === 'applied' ? ['overturn', 'escalate'] : [];
}

interface MatchedRule {
    ruleId: string;
    score: number;
    severity: string;
    kind: string;
    group: string;
    reason: string;
}

interface ViolationRow {
    id: string;
    botId: string;
    chatId: number;
    userId: number;
    messageText: string;
    matchedRules: MatchedRule[];
    groupBreakdown: Record<string, { contribution: number; cap: number }>;
    score: number;
    enforcementAction: string;
    status: string;
    evaluationSnapshot: Record<string, unknown>;
    riskContext: unknown;
    createdAt: string;
}

interface DryRunReport {
    policyVersion: string;
    rulesetVersion: string;
    matchedRules: Array<{
        ruleId: string;
        score: number;
        severity: string;
        kind: string;
        reason: string;
    }>;
    groupBreakdown: Record<string, { contribution: number; cap: number }>;
    score: number;
    globalCap: number;
    verdict: { action: string; score: number; reason: string };
    thresholds: Record<string, number>;
}

interface HistoryEvent {
    type: 'violation' | 'strike';
    id: string;
    chatId: number;
    messageId?: number;
    score?: number;
    enforcementAction?: string;
    status?: string;
    rules?: string[];
    consequence?: string;
    active?: boolean;
    expiresAt?: string | null;
    violationId?: string;
    at: string;
}

export default function AntispamViolations({
    violations,
    filters,
    bots,
    statuses,
    groups,
}: {
    violations: {
        data: ViolationRow[];
        current_page: number;
        last_page: number;
        next_page_url: string | null;
        prev_page_url: string | null;
    };
    filters: Partial<
        Record<
            | 'bot_id'
            | 'chat_id'
            | 'user_id'
            | 'status'
            | 'group'
            | 'date_from'
            | 'date_to',
            string
        >
    >;
    bots: Array<{ bot_id: string }>;
    statuses: string[];
    groups: string[];
}) {
    const [filterState, setFilterState] = useState(filters);
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [busy, setBusy] = useState(false);
    const [busyId, setBusyId] = useState<string | null>(null);
    const [historyTarget, setHistoryTarget] = useState<{
        botId: string;
        userId: number;
    } | null>(null);

    const applyFilters = () => {
        router.get(AntispamViolationsController.index().url, filterState, {
            preserveState: true,
        });
    };

    const toggleRow = (id: string) => {
        setSelected((prev) => {
            const next = new Set(prev);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    };

    const actionableRows = violations.data.filter(
        (v) => actionsFor(v.status).length > 0,
    );
    const allSelected =
        actionableRows.length > 0 &&
        actionableRows.every((v) => selected.has(v.id));

    const toggleAll = () => {
        setSelected(
            allSelected ? new Set() : new Set(actionableRows.map((v) => v.id)),
        );
    };

    const runAction = async (
        violation: ViolationRow,
        action: ModerationAction,
    ) => {
        if (
            !window.confirm(
                `${ACTION_CONFIRM[action]} (${violation.enforcementAction} → user ${violation.userId})`,
            )
        ) {
            return;
        }

        setBusyId(violation.id);

        try {
            const response = await fetch(
                AntispamViolationsController.action({
                    violationId: violation.id,
                }).url,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({ action }),
                },
            );
            const body = await response.json();

            if (!response.ok) {
                alert(body.error ?? 'Action failed');

                return;
            }

            router.reload({ only: ['violations'] });
        } finally {
            setBusyId(null);
        }
    };

    const runBulk = async (action: ModerationAction) => {
        if (
            selected.size === 0 ||
            !window.confirm(
                `${ACTION_CONFIRM[action]} (${selected.size} selected)`,
            )
        ) {
            return;
        }

        setBusy(true);

        try {
            const response = await fetch(
                AntispamViolationsController.bulkAction().url,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({ action, ids: [...selected] }),
                },
            );
            const body = await response.json();

            if (!response.ok) {
                alert('Bulk action failed');

                return;
            }

            if ((body.skipped ?? []).length > 0) {
                const reasons = body.skipped.map(
                    (s: { id: string; reason: string }) =>
                        `${s.id.slice(0, 8)}…: ${s.reason}`,
                );

                alert(`Skipped ${body.skipped.length}:\n${reasons.join('\n')}`);
            }

            setSelected(new Set());
            router.reload({ only: ['violations'] });
        } finally {
            setBusy(false);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Anti-Spam violations" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <Heading
                    title="Violations"
                    description="Moderation queue of stored detections. Apply, overturn or escalate sanctions, inspect per-user history, dry-run texts and replay stored verdicts."
                />

                <div className="flex flex-wrap items-center gap-3 text-sm">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={AntispamAppealsController.index()}>
                            Appeals →
                        </Link>
                    </Button>
                </div>

                <DryRunPanel bots={bots.map((bot) => bot.bot_id)} />

                <div className="flex flex-wrap items-end gap-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <FilterSelect
                        label="Bot"
                        value={filterState.bot_id ?? ''}
                        onChange={(v) =>
                            setFilterState((f) => ({ ...f, bot_id: v }))
                        }
                        options={bots.map((b) => b.bot_id)}
                    />
                    <FilterSelect
                        label="Status"
                        value={filterState.status ?? ''}
                        onChange={(v) =>
                            setFilterState((f) => ({ ...f, status: v }))
                        }
                        options={statuses}
                        allowEmpty={false}
                    />
                    <FilterSelect
                        label="Group"
                        value={filterState.group ?? ''}
                        onChange={(v) =>
                            setFilterState((f) => ({ ...f, group: v }))
                        }
                        options={groups}
                    />
                    <div className="space-y-1.5">
                        <Label htmlFor="violation-user">User</Label>
                        <Input
                            id="violation-user"
                            className="w-28"
                            type="number"
                            value={filterState.user_id ?? ''}
                            onChange={(e) =>
                                setFilterState((f) => ({
                                    ...f,
                                    user_id: e.target.value,
                                }))
                            }
                        />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="violation-from">From</Label>
                        <Input
                            id="violation-from"
                            className="w-36"
                            type="date"
                            value={filterState.date_from ?? ''}
                            onChange={(e) =>
                                setFilterState((f) => ({
                                    ...f,
                                    date_from: e.target.value,
                                }))
                            }
                        />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="violation-to">To</Label>
                        <Input
                            id="violation-to"
                            className="w-36"
                            type="date"
                            value={filterState.date_to ?? ''}
                            onChange={(e) =>
                                setFilterState((f) => ({
                                    ...f,
                                    date_to: e.target.value,
                                }))
                            }
                        />
                    </div>
                    <Button onClick={applyFilters}>Apply</Button>
                </div>

                <BulkBar
                    count={selected.size}
                    busy={busy}
                    onAction={(action) => void runBulk(action)}
                />

                <div className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    {violations.data.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No violations match the filters.
                        </p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left text-muted-foreground">
                                    <th className="py-2 pr-2">
                                        <input
                                            type="checkbox"
                                            aria-label="Select all"
                                            checked={allSelected}
                                            onChange={toggleAll}
                                        />
                                    </th>
                                    <th className="py-2 pr-4">When</th>
                                    <th className="py-2 pr-4">Scope</th>
                                    <th className="py-2 pr-4">Message</th>
                                    <th className="py-2 pr-4">Rules</th>
                                    <th className="py-2 pr-4">Score</th>
                                    <th className="py-2 pr-4">Action</th>
                                    <th className="py-2 pr-4">Status</th>
                                    <th className="py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {violations.data.map((violation) => (
                                    <ViolationLine
                                        key={violation.id}
                                        violation={violation}
                                        selected={selected.has(violation.id)}
                                        onToggle={() => toggleRow(violation.id)}
                                        busy={busyId === violation.id || busy}
                                        onAction={(action) =>
                                            void runAction(violation, action)
                                        }
                                        onHistory={() =>
                                            setHistoryTarget({
                                                botId: violation.botId,
                                                userId: violation.userId,
                                            })
                                        }
                                    />
                                ))}
                            </tbody>
                        </table>
                    )}

                    {violations.last_page > 1 && (
                        <p className="mt-3 text-xs text-muted-foreground">
                            Page {violations.current_page} of{' '}
                            {violations.last_page}
                        </p>
                    )}
                </div>
            </div>

            {historyTarget && (
                <HistoryDrawer
                    botId={historyTarget.botId}
                    userId={historyTarget.userId}
                    onClose={() => setHistoryTarget(null)}
                />
            )}
        </AppLayout>
    );
}

function FilterSelect({
    label,
    value,
    options,
    onChange,
    allowEmpty = true,
}: {
    label: string;
    value: string;
    options: string[];
    onChange: (value: string) => void;
    allowEmpty?: boolean;
}) {
    return (
        <div className="space-y-1.5">
            <Label>{label}</Label>
            <select
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="w-32 rounded-md border bg-transparent px-3 py-2 text-sm"
            >
                {allowEmpty && <option value="">any</option>}
                {options.map((option) => (
                    <option key={option} value={option}>
                        {option}
                    </option>
                ))}
            </select>
        </div>
    );
}

function BulkBar({
    count,
    busy,
    onAction,
}: {
    count: number;
    busy: boolean;
    onAction: (action: ModerationAction) => void;
}) {
    if (count === 0) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center gap-3 rounded-xl border border-sidebar-border/70 bg-muted/40 p-3 text-sm dark:border-sidebar-border">
            <span className="font-medium">{count} selected</span>
            <Button
                variant="outline"
                size="sm"
                disabled={busy}
                onClick={() => onAction('apply')}
            >
                Apply
            </Button>
            <Button
                variant="outline"
                size="sm"
                disabled={busy}
                onClick={() => onAction('overturn')}
            >
                Overturn
            </Button>
            <Button
                variant="outline"
                size="sm"
                disabled={busy}
                onClick={() => onAction('escalate')}
            >
                Escalate
            </Button>
        </div>
    );
}

function ViolationLine({
    violation,
    selected,
    onToggle,
    busy,
    onAction,
    onHistory,
}: {
    violation: ViolationRow;
    selected: boolean;
    onToggle: () => void;
    busy: boolean;
    onAction: (action: ModerationAction) => void;
    onHistory: () => void;
}) {
    const [replay, setReplay] = useState<{
        oldAction: string;
        newAction: string;
        newScore: number;
        changed: boolean;
        reason?: string;
    } | null>(null);

    const runReplay = () => {
        fetch(
            AntispamReplayController.compare({ violationId: violation.id }).url,
            {
                method: 'POST',
                headers: { Accept: 'application/json' },
            },
        )
            .then(async (response) =>
                setReplay(response.ok ? await response.json() : null),
            )
            .catch(() => setReplay(null));
    };

    const actions = actionsFor(violation.status);
    const actionLabels: Record<ModerationAction, string> = {
        apply: 'Apply',
        overturn: 'Overturn',
        escalate: 'Escalate',
    };

    return (
        <tr className="border-b align-top last:border-0">
            <td className="py-2 pr-2">
                {actions.length > 0 && (
                    <input
                        type="checkbox"
                        aria-label={`Select violation ${violation.id}`}
                        checked={selected}
                        onChange={onToggle}
                    />
                )}
            </td>
            <td className="py-2 pr-4 whitespace-nowrap">
                {violation.createdAt.slice(0, 16)}
            </td>
            <td className="py-2 pr-4 whitespace-nowrap">
                {violation.botId}/{violation.chatId}/u{violation.userId}
            </td>
            <td
                className="max-w-48 truncate py-2 pr-4"
                title={violation.messageText}
            >
                {violation.messageText || '(media)'}
            </td>
            <td className="py-2 pr-4">
                <div className="flex flex-wrap gap-1">
                    {violation.matchedRules.map((rule) => (
                        <Badge
                            key={rule.ruleId}
                            variant="outline"
                            title={`${rule.kind}/${rule.severity}: ${rule.reason}`}
                        >
                            {rule.ruleId} +{rule.score}
                        </Badge>
                    ))}
                </div>
            </td>
            <td className="py-2 pr-4 font-medium">{violation.score}</td>
            <td className="py-2 pr-4">{violation.enforcementAction}</td>
            <td className="py-2 pr-4">{violation.status}</td>
            <td className="py-2 text-right whitespace-nowrap">
                {actions.map((action) => (
                    <Button
                        key={action}
                        variant="outline"
                        size="sm"
                        disabled={busy}
                        className="ml-1 first:ml-0"
                        onClick={() => onAction(action)}
                    >
                        {actionLabels[action]}
                    </Button>
                ))}
                <Button
                    variant="ghost"
                    size="sm"
                    disabled={busy}
                    className="ml-1"
                    onClick={onHistory}
                >
                    History
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    disabled={busy}
                    className="ml-1"
                    onClick={runReplay}
                >
                    Replay
                </Button>
                {replay && (
                    <span
                        className={`ml-2 text-xs ${replay.changed ? 'text-red-500' : 'text-muted-foreground'}`}
                    >
                        {replay.oldAction} → {replay.newAction} (
                        {replay.newScore})
                    </span>
                )}
            </td>
        </tr>
    );
}

function formatAt(iso: string): string {
    return iso.slice(0, 19).replace('T', ' ');
}

function HistoryDrawer({
    botId,
    userId,
    onClose,
}: {
    botId: string;
    userId: number;
    onClose: () => void;
}) {
    const [events, setEvents] = useState<HistoryEvent[] | null>(null);

    useEffect(() => {
        fetch(
            AntispamViolationsController.history({
                query: { bot_id: botId, user_id: String(userId) },
            }).url,
            {
                headers: { Accept: 'application/json' },
            },
        )
            .then(async (response) =>
                setEvents(response.ok ? (await response.json()).events : []),
            )
            .catch(() => setEvents([]));
    }, [botId, userId]);

    return (
        <div className="fixed inset-0 z-50">
            <div className="absolute inset-0 bg-black/40" onClick={onClose} />
            <aside
                className="absolute top-0 right-0 flex h-full w-full max-w-md flex-col gap-4 overflow-y-auto border-l border-sidebar-border/70 bg-background p-4 shadow-xl dark:border-sidebar-border"
                role="dialog"
                aria-label={`History for ${botId}/u${userId}`}
            >
                <div className="flex items-center justify-between">
                    <h3 className="text-base font-medium">
                        History · {botId}/u{userId}
                    </h3>
                    <Button variant="outline" size="sm" onClick={onClose}>
                        Close
                    </Button>
                </div>

                {events === null ? (
                    <p className="text-sm text-muted-foreground">Loading…</p>
                ) : events.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No recorded events.
                    </p>
                ) : (
                    <ol className="space-y-3">
                        {events.map((event) => (
                            <li
                                key={`${event.type}-${event.id}`}
                                className="rounded-md border border-sidebar-border/70 p-3 text-sm dark:border-sidebar-border"
                            >
                                <div className="mb-1 flex items-center justify-between">
                                    <Badge
                                        variant={
                                            event.type === 'strike'
                                                ? 'destructive'
                                                : 'outline'
                                        }
                                    >
                                        {event.type === 'strike'
                                            ? `strike · ${event.consequence}`
                                            : 'violation'}
                                    </Badge>
                                    <span className="text-xs text-muted-foreground">
                                        {formatAt(event.at)}
                                    </span>
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    chat {event.chatId}
                                    {event.type === 'violation' ? (
                                        <>
                                            {' '}
                                            · msg {event.messageId} · score{' '}
                                            {event.score} ·{' '}
                                            {event.enforcementAction} (
                                            {event.status})
                                            {event.rules &&
                                                event.rules.length > 0 && (
                                                    <>
                                                        {' '}
                                                        ·{' '}
                                                        {event.rules.join(', ')}
                                                    </>
                                                )}
                                        </>
                                    ) : (
                                        <>
                                            {' '}
                                            ·{' '}
                                            {event.active
                                                ? 'active'
                                                : `expired ${formatAt(event.expiresAt ?? '')}`}
                                        </>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ol>
                )}
            </aside>
        </div>
    );
}

function DryRunPanel({ bots }: { bots: string[] }) {
    const [botId, setBotId] = useState(bots[0] ?? '');
    const [chatId, setChatId] = useState('100');
    const [text, setText] = useState('');
    const [report, setReport] = useState<DryRunReport | null>(null);
    const [running, setRunning] = useState(false);

    const run = async () => {
        if (text.trim() === '' || botId === '') {
            return;
        }

        setRunning(true);

        try {
            const response = await fetch(AntispamDryRunController.run().url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    bot_id: botId,
                    chat_id: Number(chatId),
                    text,
                }),
            });
            setReport(response.ok ? await response.json() : null);
        } finally {
            setRunning(false);
        }
    };

    return (
        <div className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
            <h3 className="mb-3 text-base font-medium">
                Test a message (dry run)
            </h3>
            <div className="flex flex-wrap items-start gap-3">
                <select
                    value={botId}
                    onChange={(event) => setBotId(event.target.value)}
                    className="w-32 rounded-md border bg-transparent px-3 py-2 text-sm"
                    aria-label="Bot"
                >
                    {bots.map((id) => (
                        <option key={id} value={id}>
                            {id}
                        </option>
                    ))}
                </select>
                <Input
                    type="number"
                    className="w-28"
                    placeholder="chat id"
                    value={chatId}
                    onChange={(event) => setChatId(event.target.value)}
                />
                <textarea
                    className="min-h-11 flex-1 rounded-md border bg-transparent px-3 py-2 text-sm"
                    placeholder="Paste a suspicious message…"
                    value={text}
                    onChange={(event) => setText(event.target.value)}
                />
                <Button
                    onClick={() => void run()}
                    disabled={running || bots.length === 0}
                >
                    {running ? 'Evaluating…' : 'Evaluate'}
                </Button>
            </div>

            {report && (
                <div className="mt-4 space-y-2 rounded-md bg-muted p-3 text-sm">
                    <div>
                        policy <code>{report.policyVersion}</code> · plan{' '}
                        <code>{report.rulesetVersion}</code> · score{' '}
                        <strong>
                            {report.score}/{report.globalCap}
                        </strong>{' '}
                        · verdict <strong>{report.verdict.action}</strong> (
                        {report.verdict.reason})
                    </div>
                    <ul className="list-inside list-disc">
                        {report.matchedRules.map((rule) => (
                            <li key={rule.ruleId}>
                                <code>{rule.ruleId}</code> [{rule.kind}/
                                {rule.severity}] +{rule.score} — {rule.reason}
                            </li>
                        ))}
                        {Object.entries(report.groupBreakdown).map(
                            ([group, info]) => (
                                <li key={group}>
                                    Σ {group}: {info.contribution}/{info.cap}
                                </li>
                            ),
                        )}
                    </ul>
                </div>
            )}
        </div>
    );
}
