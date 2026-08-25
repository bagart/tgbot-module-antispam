import { Form, Head, router } from '@inertiajs/react';
import AntispamUserListsController from '@/actions/BAGArt/TelegramBotAntispam/Http/Controllers/AntispamUserListsController';
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
        href: AntispamUserListsController.index(),
    },
];

interface ListEntry {
    id: string;
    list_type: string;
    bot_id: string | null;
    chat_id: number;
    user_id: number;
    reason: string | null;
    expires_at: string | null;
}

export default function AntispamUserLists({
    entries,
    bots,
}: {
    entries: { data: ListEntry[]; current_page: number; last_page: number };
    bots: Array<{ bot_id: string }>;
}) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Anti-Spam user lists" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <Heading
                    title="User lists"
                    description="Whitelist bypasses the module entirely, blacklist bypasses enforcement. Changes apply instantly."
                />

                <div className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <h3 className="mb-3 text-base font-medium">Add entry</h3>
                    <Form
                        {...AntispamUserListsController.store.form()}
                        options={{ preserveScroll: true }}
                        className="flex flex-wrap items-end gap-3"
                    >
                        {({ errors }) => (
                            <>
                                <div className="space-y-1.5">
                                    <Label htmlFor="list_type">List</Label>
                                    <select
                                        id="list_type"
                                        name="list_type"
                                        className="w-32 rounded-md border bg-transparent px-3 py-2 text-sm"
                                    >
                                        <option value="whitelist">
                                            whitelist
                                        </option>
                                        <option value="blacklist">
                                            blacklist
                                        </option>
                                    </select>
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="ul-bot">Bot</Label>
                                    <select
                                        id="ul-bot"
                                        name="bot_id"
                                        className="w-32 rounded-md border bg-transparent px-3 py-2 text-sm"
                                    >
                                        {bots.map((bot) => (
                                            <option
                                                key={bot.bot_id}
                                                value={bot.bot_id}
                                            >
                                                {bot.bot_id}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="ul-chat">Chat</Label>
                                    <Input
                                        id="ul-chat"
                                        name="chat_id"
                                        type="number"
                                        className="w-28"
                                        required
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="ul-user">User</Label>
                                    <Input
                                        id="ul-user"
                                        name="user_id"
                                        type="number"
                                        className="w-28"
                                        required
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="ul-reason">Reason</Label>
                                    <Input
                                        id="ul-reason"
                                        name="reason"
                                        className="w-56"
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="ul-expires">
                                        Expires at
                                    </Label>
                                    <Input
                                        id="ul-expires"
                                        name="expires_at"
                                        type="date"
                                        className="w-40"
                                    />
                                </div>
                                <Button type="submit">Add</Button>
                                {(errors.list_type ?? errors.bot_id) && (
                                    <p className="text-xs text-red-500">
                                        {errors.list_type ?? errors.bot_id}
                                    </p>
                                )}
                            </>
                        )}
                    </Form>
                </div>

                <div className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    {entries.data.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No list entries yet.
                        </p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left text-muted-foreground">
                                    <th className="py-2 pr-4">List</th>
                                    <th className="py-2 pr-4">Bot</th>
                                    <th className="py-2 pr-4">Chat</th>
                                    <th className="py-2 pr-4">User</th>
                                    <th className="py-2 pr-4">Reason</th>
                                    <th className="py-2 pr-4">Expires</th>
                                    <th className="py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {entries.data.map((entry) => (
                                    <tr
                                        key={entry.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="py-2 pr-4">
                                            <Badge
                                                variant={
                                                    entry.list_type ===
                                                    'whitelist'
                                                        ? 'default'
                                                        : 'destructive'
                                                }
                                            >
                                                {entry.list_type}
                                            </Badge>
                                        </td>
                                        <td className="py-2 pr-4">
                                            {entry.bot_id}
                                        </td>
                                        <td className="py-2 pr-4">
                                            {entry.chat_id}
                                        </td>
                                        <td className="py-2 pr-4">
                                            {entry.user_id}
                                        </td>
                                        <td className="max-w-48 truncate py-2 pr-4">
                                            {entry.reason?.startsWith(
                                                'blocklist:',
                                            ) ? (
                                                <Badge variant="outline">
                                                    {entry.reason}
                                                </Badge>
                                            ) : (
                                                (entry.reason ?? '')
                                            )}
                                        </td>
                                        <td className="py-2 pr-4">
                                            {entry.expires_at?.slice(0, 10) ??
                                                ''}
                                        </td>
                                        <td className="py-2 text-right">
                                            <Button
                                                variant="destructive"
                                                size="sm"
                                                onClick={() =>
                                                    router.delete(
                                                        AntispamUserListsController.destroy(
                                                            { entry: entry.id },
                                                        ).url,
                                                    )
                                                }
                                            >
                                                Delete
                                            </Button>
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
