import { Head } from '@inertiajs/react';
import AntispamDashboardController from '@/actions/BAGArt/TelegramBotAntispam/Http/Controllers/AntispamDashboardController';
import Heading from '@/components/heading';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Anti-Spam',
        href: AntispamDashboardController.index(),
    },
];

interface StatusCounts {
    pending: number;
    applied: number;
    overturned: number;
    escalated: number;
}

export default function AntispamDashboard({
    violationsByStatus,
    today,
    recentViolations,
    activeRules,
}: {
    violationsByStatus: StatusCounts;
    today: { detections: number; violations: number };
    recentViolations: Array<{
        id: string;
        botId: string;
        chatId: number;
        userId: number;
        score: number;
        enforcementAction: string;
        status: string;
        createdAt: string;
    }>;
    activeRules: number;
}) {
    const stats = [
        { label: 'Today detections', value: today.detections },
        { label: 'Today violations', value: today.violations },
        { label: 'Active DB rules', value: activeRules },
        { label: 'Pending', value: violationsByStatus.pending },
        { label: 'Applied', value: violationsByStatus.applied },
        { label: 'Overturned', value: violationsByStatus.overturned },
        { label: 'Escalated', value: violationsByStatus.escalated },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Anti-Spam" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <Heading
                    title="Anti-Spam overview"
                    description="Detections, violations and rule status across bots."
                />

                <div className="grid auto-rows-min gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {stats.map((stat) => (
                        <div
                            key={stat.label}
                            className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                        >
                            <div className="text-sm text-muted-foreground">
                                {stat.label}
                            </div>
                            <div className="mt-1 text-2xl font-semibold">
                                {stat.value}
                            </div>
                        </div>
                    ))}
                </div>

                <div className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <h3 className="mb-3 text-base font-medium">
                        Recent violations
                    </h3>
                    {recentViolations.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No violations recorded yet.
                        </p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left text-muted-foreground">
                                    <th className="py-2 pr-4">Bot</th>
                                    <th className="py-2 pr-4">Chat</th>
                                    <th className="py-2 pr-4">User</th>
                                    <th className="py-2 pr-4">Score</th>
                                    <th className="py-2 pr-4">Action</th>
                                    <th className="py-2 pr-4">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {recentViolations.map((violation) => (
                                    <tr
                                        key={violation.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="py-2 pr-4">
                                            {violation.botId}
                                        </td>
                                        <td className="py-2 pr-4">
                                            {violation.chatId}
                                        </td>
                                        <td className="py-2 pr-4">
                                            {violation.userId}
                                        </td>
                                        <td className="py-2 pr-4 font-medium">
                                            {violation.score}
                                        </td>
                                        <td className="py-2 pr-4">
                                            {violation.enforcementAction}
                                        </td>
                                        <td className="py-2 pr-4">
                                            {violation.status}
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
