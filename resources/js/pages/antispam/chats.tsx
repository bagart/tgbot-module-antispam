import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AntispamChatsController from '@/actions/BAGArt/TelegramBotAntispam/Http/Controllers/AntispamChatsController';
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
        href: AntispamChatsController.index(),
    },
];

interface ChatSettings {
    strictness: string | null;
    thresholds: Record<string, number> | null;
    group_caps: Record<string, number> | null;
    // null = inherit (all active rules); list = allowlist
    customRules: string[] | null;
    honeypotWords: string;
    captcha: { enabled: boolean; onFail: string; ttlSeconds: number } | null;
}

interface ChatRow {
    botId: string;
    chatId: number;
    enabled: boolean;
    settings: ChatSettings;
    rulesetVersion: string;
}

type RuleMode = 'inherit' | 'allowlist';

export default function AntispamChats({
    chats,
    knownRuleIds,
}: {
    chats: ChatRow[];
    knownRuleIds: string[];
}) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Anti-Spam chat settings" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <Heading
                    title="Chat settings"
                    description="Strictness, thresholds and rule allowlists per chat. Saved settings recompile the policy plan immediately."
                />

                {chats.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No chat-level antispam enablements yet.
                    </p>
                ) : (
                    chats.map((chat) => (
                        <ChatCard
                            key={`${chat.botId}:${chat.chatId}`}
                            chat={chat}
                            knownRuleIds={knownRuleIds}
                        />
                    ))
                )}
            </div>
        </AppLayout>
    );
}

function ChatCard({
    chat,
    knownRuleIds,
}: {
    chat: ChatRow;
    knownRuleIds: string[];
}) {
    const [mode, setMode] = useState<RuleMode>(
        chat.settings.customRules === null ? 'inherit' : 'allowlist',
    );
    const [allowlist, setAllowlist] = useState<string[]>(
        chat.settings.customRules ?? [],
    );
    const [captchaOn, setCaptchaOn] = useState<boolean>(
        chat.settings.captcha?.enabled ?? false,
    );
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    const save = () => {
        if (mode === 'allowlist' && allowlist.length === 0) {
            setError(
                'An empty allowlist would disable every rule; switch to inherit instead.',
            );

            return;
        }

        setSaving(true);
        setError(null);

        const thresholds: Record<string, number> = {};

        for (const level of ['warn', 'restrict', 'ban']) {
            const raw = document.querySelector<HTMLInputElement>(
                `[name="${level}-${chat.chatId}"]`,
            )?.value;

            if (raw !== undefined && raw !== '') {
                thresholds[level] = Number(raw);
            }
        }

        router.put(
            AntispamChatsController.updateSettings({
                botId: chat.botId,
                chatId: chat.chatId,
            }).url,
            {
                strictness:
                    document.querySelector<HTMLSelectElement>(
                        `[name="strictness-${chat.chatId}"]`,
                    )?.value || null,
                thresholds:
                    Object.keys(thresholds).length > 0 ? thresholds : null,
                custom_rules: mode === 'inherit' ? null : allowlist,
                honeypot_words:
                    document.querySelector<HTMLInputElement>(
                        `[name="honeypot-${chat.chatId}"]`,
                    )?.value ?? '',
                captcha_enabled: captchaOn,
                captcha_on_fail: document.querySelector<HTMLSelectElement>(
                    `[name="captcha-fail-${chat.chatId}"]`,
                )?.value,
                captcha_ttl_seconds: Number(
                    document.querySelector<HTMLInputElement>(
                        `[name="captcha-ttl-${chat.chatId}"]`,
                    )?.value ?? 300,
                ),
            },
            {
                preserveScroll: true,
                onFinish: () => setSaving(false),
                onError: (errors) =>
                    setError(
                        String(
                            errors.custom_rules ??
                                errors.thresholds ??
                                'Failed to save.',
                        ),
                    ),
            },
        );
    };

    return (
        <div className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
            <div className="mb-4 flex items-center gap-3">
                <h3 className="text-base font-medium">
                    {chat.botId} · chat {chat.chatId}
                </h3>
                <Badge variant={chat.enabled ? 'default' : 'secondary'}>
                    {chat.enabled ? 'enabled' : 'disabled'}
                </Badge>
                <span
                    className="ml-auto font-mono text-xs text-muted-foreground"
                    title="Compiled plan version"
                >
                    plan {chat.rulesetVersion}
                </span>
            </div>

            <div className="space-y-4">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="space-y-1.5">
                        <Label htmlFor={`strictness-${chat.chatId}`}>
                            Strictness
                        </Label>
                        <select
                            name={`strictness-${chat.chatId}`}
                            id={`strictness-${chat.chatId}`}
                            defaultValue={chat.settings.strictness ?? ''}
                            className="w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                        >
                            <option value="">inherit (normal)</option>
                            <option value="relaxed">relaxed</option>
                            <option value="normal">normal</option>
                            <option value="strict">strict</option>
                        </select>
                    </div>
                    {(['warn', 'restrict', 'ban'] as const).map((level) => (
                        <div key={level} className="space-y-1.5">
                            <Label htmlFor={`${level}-${chat.chatId}`}>
                                Threshold: {level}
                            </Label>
                            <Input
                                id={`${level}-${chat.chatId}`}
                                name={`${level}-${chat.chatId}`}
                                type="number"
                                min={1}
                                defaultValue={
                                    chat.settings.thresholds?.[level] ?? ''
                                }
                                placeholder="inherit"
                            />
                        </div>
                    ))}
                </div>

                <div className="space-y-2">
                    <div className="flex items-center gap-4 text-sm">
                        <label className="flex items-center gap-2">
                            <input
                                type="radio"
                                name={`mode-${chat.chatId}`}
                                checked={mode === 'inherit'}
                                onChange={() => setMode('inherit')}
                            />
                            All active rules (inherit)
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="radio"
                                name={`mode-${chat.chatId}`}
                                checked={mode === 'allowlist'}
                                onChange={() => setMode('allowlist')}
                            />
                            Allowlist only
                        </label>
                    </div>

                    {mode === 'allowlist' && (
                        <div className="flex flex-wrap gap-3 rounded-md border p-3 text-sm">
                            {knownRuleIds.map((ruleId) => (
                                <label
                                    key={ruleId}
                                    className="flex items-center gap-2"
                                >
                                    <input
                                        type="checkbox"
                                        checked={allowlist.includes(ruleId)}
                                        onChange={(event) =>
                                            setAllowlist((current) =>
                                                event.target.checked
                                                    ? [...current, ruleId]
                                                    : current.filter(
                                                          (id) => id !== ruleId,
                                                      ),
                                            )
                                        }
                                    />
                                    {ruleId}
                                </label>
                            ))}
                        </div>
                    )}
                    {error && <p className="text-xs text-red-500">{error}</p>}
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor={`honeypot-${chat.chatId}`}>
                        Honeypot trigger words
                    </Label>
                    <Input
                        id={`honeypot-${chat.chatId}`}
                        name={`honeypot-${chat.chatId}`}
                        type="text"
                        defaultValue={chat.settings.honeypotWords ?? ''}
                        placeholder="comma-separated, empty = disabled"
                    />
                    <p className="text-xs text-muted-foreground">
                        A message containing any of these words is an instant
                        hard violation.
                    </p>
                </div>

                <div className="space-y-2 rounded-md border p-3">
                    <label className="flex items-center gap-2 text-sm font-medium">
                        <input
                            type="checkbox"
                            name={`captcha-enabled-${chat.chatId}`}
                            checked={captchaOn}
                            onChange={(event) =>
                                setCaptchaOn(event.target.checked)
                            }
                        />
                        CAPTCHA challenge for new joiners
                    </label>
                    {captchaOn && (
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <div className="space-y-1.5">
                                <Label
                                    htmlFor={`captcha-fail-${chat.chatId}`}
                                >
                                    On fail / timeout
                                </Label>
                                <select
                                    name={`captcha-fail-${chat.chatId}`}
                                    id={`captcha-fail-${chat.chatId}`}
                                    defaultValue={
                                        chat.settings.captcha?.onFail ?? 'ban'
                                    }
                                    className="w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                                >
                                    <option value="ban">ban</option>
                                    <option value="kick">kick (rejoinable)</option>
                                </select>
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor={`captcha-ttl-${chat.chatId}`}>
                                    Challenge TTL (seconds)
                                </Label>
                                <Input
                                    id={`captcha-ttl-${chat.chatId}`}
                                    name={`captcha-ttl-${chat.chatId}`}
                                    type="number"
                                    min={30}
                                    max={3600}
                                    defaultValue={
                                        chat.settings.captcha?.ttlSeconds ?? 300
                                    }
                                />
                            </div>
                        </div>
                    )}
                    <p className="text-xs text-muted-foreground">
                        Joiners are muted until they pass the challenge; pass
                        adds a 1h whitelist entry, wrong click or timeout bans
                        or kicks per the setting above.
                    </p>
                </div>

                <Button onClick={save} disabled={saving}>
                    {saving ? 'Saving…' : 'Save settings'}
                </Button>
            </div>
        </div>
    );
}
