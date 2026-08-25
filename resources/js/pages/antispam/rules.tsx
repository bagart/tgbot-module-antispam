import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AntispamRulesController from '@/actions/BAGArt/TelegramBotAntispam/Http/Controllers/AntispamRulesController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Anti-Spam',
        href: AntispamRulesController.index(),
    },
];

interface DbRule {
    id: string;
    bot_id: string | null;
    name: string;
    group_id: string;
    type: string;
    config: Record<string, unknown> | null;
    score_weight: number;
    severity: string;
    kind: string;
    priority: number;
    is_active: boolean;
    cooldown_seconds: number | null;
}

export default function AntispamRules({
    dbRules,
    builtinRules,
    groups,
}: {
    dbRules: DbRule[];
    builtinRules: Array<{ id: string; group: string }>;
    groups: Record<string, number>;
}) {
    const [editing, setEditing] = useState<DbRule | null>(null);
    const [open, setOpen] = useState(false);

    const startCreate = () => {
        setEditing(null);
        setOpen(true);
    };

    const startEdit = (rule: DbRule) => {
        setEditing(rule);
        setOpen(true);
    };

    const remove = (rule: DbRule) => {
        router.delete(AntispamRulesController.destroy({ rule: rule.id }).url);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Anti-Spam rules" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        title="Rules"
                        description="DB overrides win over platform defaults and are compiled into the policy plan instantly."
                    />
                    <Button onClick={startCreate}>New rule</Button>
                </div>

                <div className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <h3 className="mb-2 text-sm font-medium text-muted-foreground">
                        Built-in catalog
                    </h3>
                    <div className="flex flex-wrap gap-2">
                        {builtinRules.map((rule) => (
                            <span
                                key={rule.id}
                                className="rounded-md bg-muted px-2 py-1 text-xs"
                                title={`group: ${rule.group}`}
                            >
                                {rule.id}
                            </span>
                        ))}
                    </div>
                    <p className="mt-3 text-xs text-muted-foreground">
                        Group caps:{' '}
                        {Object.entries(groups)
                            .map(([group, cap]) => `${group} ${cap}`)
                            .join(' · ')}
                    </p>
                </div>

                <div className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <h3 className="mb-3 text-base font-medium">DB rules</h3>
                    {dbRules.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No DB rules yet — built-in defaults apply.
                        </p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left text-muted-foreground">
                                    <th className="py-2 pr-4">Name</th>
                                    <th className="py-2 pr-4">Scope</th>
                                    <th className="py-2 pr-4">Group</th>
                                    <th className="py-2 pr-4">Score</th>
                                    <th className="py-2 pr-4">Severity</th>
                                    <th className="py-2 pr-4">Kind</th>
                                    <th className="py-2 pr-4">Active</th>
                                    <th className="py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {dbRules.map((rule) => (
                                    <tr
                                        key={rule.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="py-2 pr-4 font-medium">
                                            {rule.name}
                                        </td>
                                        <td className="py-2 pr-4">
                                            {rule.bot_id ?? 'platform'}
                                        </td>
                                        <td className="py-2 pr-4">
                                            {rule.group_id}
                                        </td>
                                        <td className="py-2 pr-4">
                                            {rule.score_weight}
                                        </td>
                                        <td className="py-2 pr-4">
                                            {rule.severity}
                                        </td>
                                        <td className="py-2 pr-4">
                                            {rule.kind}
                                        </td>
                                        <td className="py-2 pr-4">
                                            {rule.is_active ? 'yes' : 'no'}
                                        </td>
                                        <td className="space-x-2 py-2 text-right">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => startEdit(rule)}
                                            >
                                                Edit
                                            </Button>
                                            <Button
                                                variant="destructive"
                                                size="sm"
                                                onClick={() => remove(rule)}
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

                <Dialog open={open} onOpenChange={setOpen}>
                    <DialogContent className="max-h-[90vh] max-w-lg overflow-y-auto">
                        <DialogHeader>
                            <DialogTitle>
                                {editing
                                    ? `Edit rule ${editing.name}`
                                    : 'New rule'}
                            </DialogTitle>
                        </DialogHeader>

                        <Form
                            {...(editing
                                ? AntispamRulesController.update.form({
                                      rule: editing.id,
                                  })
                                : AntispamRulesController.store.form())}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setOpen(false)}
                            className="space-y-4"
                        >
                            {({ errors }) => (
                                <>
                                    <div className="grid grid-cols-2 gap-3">
                                        <div className="col-span-2 space-y-1.5">
                                            <Label htmlFor="name">
                                                Name (must match a rule id)
                                            </Label>
                                            <Input
                                                id="name"
                                                name="name"
                                                defaultValue={
                                                    editing?.name ?? ''
                                                }
                                                required
                                            />
                                            {errors.name && (
                                                <p className="text-xs text-red-500">
                                                    {errors.name}
                                                </p>
                                            )}
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label htmlFor="bot_id">
                                                Bot scope
                                            </Label>
                                            <select
                                                id="bot_id"
                                                name="bot_id"
                                                defaultValue={
                                                    editing?.bot_id ?? ''
                                                }
                                                className="w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                                            >
                                                <option value="">
                                                    platform default
                                                </option>
                                            </select>
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label htmlFor="group_id">
                                                Group
                                            </Label>
                                            <select
                                                id="group_id"
                                                name="group_id"
                                                defaultValue={
                                                    editing?.group_id ??
                                                    'advertising'
                                                }
                                                className="w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                                            >
                                                {Object.keys(groups).map(
                                                    (group) => (
                                                        <option
                                                            key={group}
                                                            value={group}
                                                        >
                                                            {group} (cap{' '}
                                                            {groups[group]})
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label htmlFor="type">Type</Label>
                                            <select
                                                id="type"
                                                name="type"
                                                defaultValue={
                                                    editing?.type ?? 'keyword'
                                                }
                                                className="w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                                            >
                                                {[
                                                    'regex',
                                                    'keyword',
                                                    'url',
                                                    'window',
                                                    'repeat',
                                                    'size',
                                                ].map((type) => (
                                                    <option
                                                        key={type}
                                                        value={type}
                                                    >
                                                        {type}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label htmlFor="score_weight">
                                                Score weight
                                            </Label>
                                            <Input
                                                id="score_weight"
                                                name="score_weight"
                                                type="number"
                                                min={1}
                                                max={200}
                                                defaultValue={
                                                    editing?.score_weight ?? 30
                                                }
                                            />
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label htmlFor="severity">
                                                Severity
                                            </Label>
                                            <select
                                                id="severity"
                                                name="severity"
                                                defaultValue={
                                                    editing?.severity ??
                                                    'medium'
                                                }
                                                className="w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                                            >
                                                {[
                                                    'info',
                                                    'low',
                                                    'medium',
                                                    'high',
                                                    'critical',
                                                ].map((severity) => (
                                                    <option
                                                        key={severity}
                                                        value={severity}
                                                    >
                                                        {severity}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label htmlFor="kind">Kind</Label>
                                            <select
                                                id="kind"
                                                name="kind"
                                                defaultValue={
                                                    editing?.kind ?? 'soft'
                                                }
                                                className="w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                                            >
                                                <option value="soft">
                                                    soft
                                                </option>
                                                <option value="hard">
                                                    hard
                                                </option>
                                            </select>
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label htmlFor="priority">
                                                Priority
                                            </Label>
                                            <Input
                                                id="priority"
                                                name="priority"
                                                type="number"
                                                min={1}
                                                max={1000}
                                                defaultValue={
                                                    editing?.priority ?? 100
                                                }
                                            />
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label htmlFor="cooldown_seconds">
                                                Cooldown (seconds)
                                            </Label>
                                            <Input
                                                id="cooldown_seconds"
                                                name="cooldown_seconds"
                                                type="number"
                                                min={0}
                                                defaultValue={
                                                    editing?.cooldown_seconds ??
                                                    ''
                                                }
                                            />
                                        </div>
                                        <label className="col-span-2 flex items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                name="is_active"
                                                value="1"
                                                defaultChecked={
                                                    editing?.is_active ?? true
                                                }
                                            />
                                            Active
                                        </label>
                                    </div>
                                    <DialogFooter>
                                        <Button type="submit">
                                            {editing ? 'Save' : 'Create'}
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
