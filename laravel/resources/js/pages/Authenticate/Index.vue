<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    AlertCircle,
    CheckCircle2,
    Copy,
    Download,
    Loader2,
    Mail,
    Plug,
    Play,
    RefreshCcw,
    RotateCcw,
    ShieldCheck,
    Sparkles,
    Trash2,
    Wand2,
    XCircle,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useExtension } from '@/composables/useExtension';
import { useUserChannel } from '@/composables/useRealtime';

interface BexSessionRow {
    id: number;
    environment: 'production' | 'staging';
    account_email: string | null;
    account_name: string | null;
    captured_at: string | null;
    last_validated_at: string | null;
    expired_at: string | null;
    is_active: boolean;
    cookie_count: number;
    earliest_cookie_expiry: string | null;
}

defineProps<{
    sessions: BexSessionRow[];
    environments: ('production' | 'staging')[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Sessions', href: '/authenticate' }] },
});

const page = usePage();
const instance = computed(
    () =>
        ((page.props as any).instance as { origin: string; host: string; name: string }) ?? {
            origin: window.location.origin,
            host: window.location.host,
            name: 'BexLogs',
        },
);

const extension = computed(
    () =>
        ((page.props as any).extension as { latestVersion: string; minVersion: string; downloadUrl: string } | undefined) ?? {
            latestVersion: '',
            minVersion: '',
            downloadUrl: '',
        },
);

const { info: extInfo, link: linkExtension } = useExtension(instance.value.origin);

interface PairingState {
    token: string;
    pasteCode: string;
    environment: 'production' | 'staging';
    expiresAt: string;
    status: 'waiting' | 'ready' | 'expired' | 'unknown';
    sessionPreview: { account_email: string | null; environment: string } | null;
    sentToExtension: boolean;
    /**
     * 'relink' when the user is re-authenticating a specific expired
     * session (targetSession filled). 'pair' is the fresh-link flow.
     * The dialog copy + status toasts key off this so re-auths never
     * look like they're going to create a new row.
     */
    mode: 'pair' | 'relink';
    targetSession: { id: number; account_email: string | null } | null;
}

const dialogOpen = ref(false);
const selectedEnvironment = ref<'production' | 'staging'>('production');
const pairing = ref<PairingState | null>(null);
const submitting = ref(false);
const relinkingSessionId = ref<number | null>(null);
const validating = ref<Record<number, boolean>>({});

let pollTimer: ReturnType<typeof setInterval> | null = null;
function clearPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}
onBeforeUnmount(clearPolling);

function csrf(): string {
    return (
        document.head.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
    );
}

async function startPairing(options: {
    mode?: 'pair' | 'relink';
    targetSession?: BexSessionRow | null;
    environment?: 'production' | 'staging';
} = {}) {
    const mode = options.mode ?? 'pair';
    const targetSession = options.targetSession ?? null;
    const environment = options.environment ?? targetSession?.environment ?? selectedEnvironment.value;

    if (mode === 'relink' && targetSession) {
        relinkingSessionId.value = targetSession.id;
    }

    submitting.value = true;
    pairing.value = null;

    try {
        const res = await fetch('/authenticate/start', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({ environment }),
        });

        if (!res.ok) {
            toast.error('Could not start pairing');

            return;
        }

        const data = await res.json();
        const sentToExtension =
            extInfo.value.detected && extInfo.value.isLinkedHere && tryHandoff(data.token, environment);

        pairing.value = {
            token: data.token,
            pasteCode: data.paste_code,
            environment: data.environment,
            expiresAt: data.expires_at,
            status: 'waiting',
            sessionPreview: null,
            sentToExtension,
            mode,
            targetSession: targetSession
                ? { id: targetSession.id, account_email: targetSession.account_email }
                : null,
        };
        dialogOpen.value = true;
        startPolling(data.token);
    } finally {
        submitting.value = false;
    }
}

function reauthenticateSession(session: BexSessionRow) {
    void startPairing({ mode: 'relink', targetSession: session });
}

function tryHandoff(token: string, environment: 'production' | 'staging'): boolean {
    try {
        window.postMessage(
            {
                source: 'bexlogs-app',
                type: 'BEXLOGS_EXT_PAIR',
                token,
                environment,
                origin: instance.value.origin,
            },
            window.location.origin,
        );

        return true;
    } catch {
        return false;
    }
}

function startPolling(token: string) {
    clearPolling();
    pollTimer = setInterval(async () => {
        if (!pairing.value || pairing.value.token !== token) {
            clearPolling();

            return;
        }

        const res = await fetch(
            `/authenticate/status?token=${encodeURIComponent(token)}`,
            { headers: { Accept: 'application/json' } },
        );

        if (!res.ok) {
return;
}

        const data = await res.json();
        pairing.value.status = data.status;

        if (data.status === 'ready') {
            pairing.value.sessionPreview = {
                account_email: data.session?.account_email ?? null,
                environment: data.session?.environment ?? pairing.value.environment,
            };
            clearPolling();
            // The backend reuses an existing row when the captured
            // cookies map to a known account_email; detect that by
            // comparing the resolved session id against our target
            // (for explicit relinks) or against the current sessions
            // list (for auto-relinks from the extension popup).
            const reusedExistingRow =
                pairing.value.targetSession?.id === data.session?.id;

            if (pairing.value.mode === 'relink' || reusedExistingRow) {
                toast.success('BookingExperts session re-authenticated');
            } else {
                toast.success('BookingExperts authenticated');
            }

            relinkingSessionId.value = null;
            router.reload({ only: ['sessions', 'jobSummary'] });
            setTimeout(() => (dialogOpen.value = false), 1500);
        } else if (data.status === 'expired') {
            clearPolling();
            relinkingSessionId.value = null;
            toast.error('Pairing code expired. Generate a new one.');
        } else if (data.status === 'unknown') {
            clearPolling();
            relinkingSessionId.value = null;
        }
    }, 1000);
}

function copyToken() {
    if (!pairing.value) {
return;
}

    navigator.clipboard.writeText(pairing.value.token);
    toast.success('Pairing token copied');
}

function reloadPage() {
    window.location.reload();
}

function revokeSession(id: number) {
    if (!confirm('Revoke this BookingExperts session?')) {
return;
}

    router.delete(`/bex-sessions/${id}`, { preserveScroll: true });
}

async function validateNow(session: BexSessionRow) {
    validating.value = { ...validating.value, [session.id]: true };

    try {
        const res = await fetch(`/bex-sessions/${session.id}/validate`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
        });

        if (!res.ok) {
            toast.error('Could not validate session');

            return;
        }

        const data = await res.json();
        toast[data.result?.is_active ? 'success' : 'warning'](data.result?.message ?? 'Validated');
        router.reload({ only: ['sessions', 'jobSummary'] });
    } finally {
        validating.value = { ...validating.value, [session.id]: false };
    }
}

function relativeOrAbsolute(iso: string | null): string {
    if (!iso) {
return 'never';
}

    const d = new Date(iso);
    const diff = Date.now() - d.getTime();
    const sec = Math.round(diff / 1000);

    if (sec < 60) {
return `${sec}s ago`;
}

    const min = Math.round(sec / 60);

    if (min < 60) {
return `${min}m ago`;
}

    const hr = Math.round(min / 60);

    if (hr < 48) {
return `${hr}h ago`;
}

    return d.toLocaleDateString();
}

function expiryHint(iso: string | null): { label: string; tone: 'success' | 'warning' | 'destructive' } | null {
    if (!iso) {
return null;
}

    const ms = new Date(iso).getTime() - Date.now();

    if (ms <= 0) {
return { label: 'cookies expired', tone: 'destructive' };
}

    const day = ms / (1000 * 60 * 60 * 24);

    if (day < 1) {
        const hr = Math.max(1, Math.round(ms / (1000 * 60 * 60)));

        return { label: `cookies expire in ${hr}h`, tone: 'warning' };
    }

    return { label: `cookies expire in ${Math.round(day)}d`, tone: day < 7 ? 'warning' : 'success' };
}

const extensionStatus = computed(() => {
    if (!extInfo.value.detected) {
        return { tone: 'destructive' as const, title: 'Extension not detected', body: 'Install the BexLogs extension to capture sessions.' };
    }

    if (!extInfo.value.isLinkedHere) {
        return {
            tone: 'warning' as const,
            title: 'Extension installed but not linked here',
            body: extInfo.value.linkedOrigin
                ? `Currently linked to ${extInfo.value.linkedOrigin}.`
                : 'Click "Link extension" to bind it to this instance.',
        };
    }

    return {
        tone: 'success' as const,
        title: `Extension linked (v${extInfo.value.version ?? '?'})`,
        body: 'Pairing codes will be handed off automatically.',
    };
});

// Refresh the card list whenever the backend relinks an existing row
// (BexSessionController::store → BexSessionRelinked). `user.{id}` is
// the firehose the rest of the app already listens on, so no new
// channel is needed.
useUserChannel({
    'bex-session-relinked': () => router.reload({ only: ['sessions', 'jobSummary'] }),
});

/**
 * Auto-relink hook for the extension popup's "Re-authenticate" button.
 *
 * When the popup opens `/authenticate?relink=1&session=<id>` in a new
 * tab, we:
 *   1. Identify the target session by id (or, as a fallback, the most
 *      recent expired session for the extension's default environment).
 *   2. Immediately kick off startPairing({ mode: 'relink' }) so the
 *      user only sees the dialog — no extra clicks.
 *   3. Strip the query string afterwards so a reload doesn't re-fire.
 */
onMounted(() => {
    if (typeof window === 'undefined') {
        return;
    }

    const params = new URLSearchParams(window.location.search);

    if (params.get('relink') !== '1') {
        return;
    }

    const idParam = params.get('session');
    const targetId = idParam ? Number(idParam) : NaN;
    const byId = Number.isFinite(targetId)
        ? (page.props as any).sessions?.find?.((s: BexSessionRow) => s.id === targetId)
        : null;
    const targetSession: BexSessionRow | null =
        byId
        ?? (page.props as any).sessions?.find?.((s: BexSessionRow) => !s.is_active)
        ?? (page.props as any).sessions?.[0]
        ?? null;

    params.delete('relink');
    params.delete('session');
    const nextQs = params.toString();
    const cleanPath = window.location.pathname + (nextQs ? '?' + nextQs : '');
    window.history.replaceState({}, '', cleanPath);

    void startPairing({
        mode: targetSession ? 'relink' : 'pair',
        targetSession,
        environment: targetSession?.environment,
    });
});
</script>

<template>
    <div>
        <Head title="Sessions" />

        <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
            <header class="flex flex-col gap-2">
                <h1 class="text-2xl font-semibold tracking-tight">BookingExperts sessions</h1>
                <p class="text-muted-foreground max-w-2xl text-sm">
                    BexLogs reuses real browser cookies to talk to BookingExperts. You log in once
                    (Microsoft SSO and all) in your normal browser via the BexLogs extension, and
                    the captured cookies are encrypted at rest and used by the headless worker.
                </p>
            </header>

            <!-- Extension status strip -->
            <Card
                :class="{
                    'border-success/40': extensionStatus.tone === 'success',
                    'border-warning/40': extensionStatus.tone === 'warning',
                    'border-destructive/40': extensionStatus.tone === 'destructive',
                }"
            >
                <CardContent class="flex flex-wrap items-center justify-between gap-4 py-4">
                    <div class="flex items-center gap-3">
                        <div
                            :class="[
                                'flex size-10 items-center justify-center rounded-full',
                                extensionStatus.tone === 'success' && 'bg-success/15 text-success',
                                extensionStatus.tone === 'warning' && 'bg-warning/15 text-warning',
                                extensionStatus.tone === 'destructive' && 'bg-destructive/15 text-destructive',
                            ]"
                        >
                            <Plug class="size-5" />
                        </div>
                        <div>
                            <p class="text-sm font-medium">{{ extensionStatus.title }}</p>
                            <p class="text-muted-foreground text-xs">{{ extensionStatus.body }}</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            v-if="extInfo.detected && !extInfo.isLinkedHere"
                            size="sm"
                            @click="linkExtension(instance.name)"
                        >
                            <Wand2 class="mr-1 size-4" /> Link extension
                        </Button>
                        <Button
                            v-if="!extInfo.detected"
                            variant="outline"
                            size="sm"
                            title="Manual fallback if the extension was just installed and the page hasn't picked it up yet"
                            @click="reloadPage"
                        >
                            <RefreshCcw class="mr-1 size-4" /> Reload page
                        </Button>
                        <Button as-child variant="outline" size="sm">
                            <a :href="extension.downloadUrl" download>
                                <Download class="mr-1 size-4" /> Download v{{ extension.latestVersion }}
                            </a>
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <!-- Pair flow -->
            <Card>
                <CardHeader class="flex-row items-start justify-between gap-3">
                    <div>
                        <CardTitle class="flex items-center gap-2">
                            <Sparkles class="size-5" /> Pair a new session
                        </CardTitle>
                        <CardDescription>
                            Generates a one-time code. With the extension linked, it's handed off
                            automatically — otherwise paste it into the extension popup.
                        </CardDescription>
                    </div>
                </CardHeader>
                <CardContent class="flex flex-wrap items-end gap-3">
                    <div class="flex flex-col gap-1">
                        <label class="text-muted-foreground text-xs uppercase tracking-wide">Environment</label>
                        <div class="flex rounded-md border p-0.5">
                            <button
                                v-for="env in environments"
                                :key="env"
                                type="button"
                                class="rounded px-3 py-1 text-sm capitalize"
                                :class="
                                    selectedEnvironment === env
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:bg-muted'
                                "
                                @click="selectedEnvironment = env"
                            >
                                {{ env }}
                            </button>
                        </div>
                    </div>
                    <Button :disabled="submitting" @click="startPairing">
                        <Loader2 v-if="submitting" class="mr-1 size-4 animate-spin" />
                        <Play v-else class="mr-1 size-4" />
                        Generate code
                    </Button>
                    <p
                        v-if="extInfo.detected && extInfo.isLinkedHere"
                        class="text-success ml-auto inline-flex items-center gap-1 text-xs"
                    >
                        <ShieldCheck class="size-4" /> Auto-handoff active
                    </p>
                </CardContent>
            </Card>

            <!-- Sessions -->
            <section>
                <header class="mb-2 flex items-center justify-between">
                    <h2 class="text-lg font-medium">Stored sessions</h2>
                    <p class="text-muted-foreground text-xs">
                        {{ sessions.filter((s) => s.is_active).length }} active · {{ sessions.length }} total
                    </p>
                </header>

                <div
                    v-if="!sessions.length"
                    class="border-border text-muted-foreground rounded-lg border border-dashed p-8 text-center text-sm"
                >
                    No sessions yet. Pair one above.
                </div>

                <div v-else class="grid gap-3 md:grid-cols-2">
                    <article
                        v-for="session in sessions"
                        :key="session.id"
                        class="border-border bg-card rounded-lg border p-4 transition-shadow hover:shadow-sm"
                        :class="!session.is_active && 'opacity-80'"
                    >
                        <header class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-start gap-3">
                                <div
                                    :class="[
                                        'mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full',
                                        session.is_active ? 'bg-success/15 text-success' : 'bg-muted text-muted-foreground',
                                    ]"
                                >
                                    <CheckCircle2 v-if="session.is_active" class="size-5" />
                                    <AlertCircle v-else class="size-5" />
                                </div>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold">
                                        {{ session.account_name ?? session.account_email ?? `Session #${session.id}` }}
                                    </p>
                                    <p class="text-muted-foreground flex items-center gap-1 truncate text-xs">
                                        <Mail v-if="session.account_email" class="size-3" />
                                        {{ session.account_email ?? '— no email captured —' }}
                                    </p>
                                </div>
                            </div>
                            <div class="flex flex-col items-end gap-1">
                                <Badge :variant="session.environment === 'production' ? 'default' : 'secondary'" class="capitalize">
                                    {{ session.environment }}
                                </Badge>
                                <Badge :variant="session.is_active ? 'success' : 'destructive'">
                                    {{ session.is_active ? 'active' : 'expired' }}
                                </Badge>
                            </div>
                        </header>

                        <dl class="text-muted-foreground mt-4 grid grid-cols-2 gap-y-1.5 text-xs">
                            <dt>Captured</dt>
                            <dd class="text-foreground text-right">{{ relativeOrAbsolute(session.captured_at) }}</dd>
                            <dt>Last validated</dt>
                            <dd class="text-foreground text-right">{{ relativeOrAbsolute(session.last_validated_at) }}</dd>
                            <dt>Cookies</dt>
                            <dd class="text-foreground text-right tabular-nums">{{ session.cookie_count }}</dd>
                            <template v-if="expiryHint(session.earliest_cookie_expiry)">
                                <dt>Cookie TTL</dt>
                                <dd class="text-right">
                                    <Badge :variant="expiryHint(session.earliest_cookie_expiry)!.tone">
                                        {{ expiryHint(session.earliest_cookie_expiry)!.label }}
                                    </Badge>
                                </dd>
                            </template>
                        </dl>

                        <!--
                            Expired sessions get a prominent
                            "Re-authenticate" button: it reuses this
                            exact row (BexSessionController matches on
                            account_email) so scrape jobs keep their
                            original bex_session_id.
                        -->
                        <div
                            v-if="!session.is_active"
                            class="border-warning/40 bg-warning/5 mt-3 flex items-center justify-between gap-3 rounded-md border p-3"
                        >
                            <div class="text-xs">
                                <p class="font-medium">Re-authenticate this session</p>
                                <p class="text-muted-foreground">
                                    Refreshes cookies on row #{{ session.id }} — no new session row.
                                </p>
                            </div>
                            <Button
                                size="sm"
                                :disabled="submitting && relinkingSessionId === session.id"
                                @click="reauthenticateSession(session)"
                            >
                                <Loader2
                                    v-if="submitting && relinkingSessionId === session.id"
                                    class="mr-1 size-4 animate-spin"
                                />
                                <RotateCcw v-else class="mr-1 size-4" />
                                Re-authenticate
                            </Button>
                        </div>

                        <footer class="mt-4 flex items-center justify-between gap-2">
                            <p class="text-muted-foreground inline-flex min-w-0 items-center gap-1 text-xs">
                                <template v-if="session.is_active">
                                    <ShieldCheck class="size-3 shrink-0" />
                                    <span v-if="session.last_validated_at" class="truncate">
                                        Auto-checked {{ relativeOrAbsolute(session.last_validated_at) }} · re-checks hourly
                                    </span>
                                    <span v-else class="truncate">Auto-checks every hour</span>
                                </template>
                                <template v-else>
                                    <AlertCircle class="size-3 shrink-0" />
                                    <span class="truncate">Last check: {{ relativeOrAbsolute(session.last_validated_at) }}</span>
                                </template>
                            </p>
                            <div class="flex shrink-0 items-center gap-1">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    title="Re-validate now"
                                    :disabled="!!validating[session.id]"
                                    @click="validateNow(session)"
                                >
                                    <Loader2 v-if="validating[session.id]" class="size-4 animate-spin" />
                                    <RefreshCcw v-else class="size-4" />
                                </Button>
                                <Button variant="ghost" size="sm" title="Revoke session" @click="revokeSession(session.id)">
                                    <Trash2 class="text-destructive size-4" />
                                </Button>
                            </div>
                        </footer>
                    </article>
                </div>
            </section>

            <!-- Pairing dialog -->
            <Dialog v-model:open="dialogOpen">
                <DialogContent class="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            <template v-if="pairing?.mode === 'relink'">
                                Re-authenticate session
                                <template v-if="pairing.targetSession?.account_email">
                                    ({{ pairing.targetSession.account_email }})
                                </template>
                            </template>
                            <template v-else>Pair with the BexLogs extension</template>
                        </DialogTitle>
                        <DialogDescription>
                            <template v-if="pairing?.mode === 'relink'">
                                Refreshing cookies on session #{{ pairing.targetSession?.id }}. No
                                new session will be created — we reuse the row as long as you sign
                                in to the same BookingExperts account.
                            </template>
                            <template v-else-if="pairing?.sentToExtension">
                                The extension has been handed the pairing code automatically.
                                Watch its popup for the BookingExperts login tab.
                            </template>
                            <template v-else>
                                Open the BexLogs extension popup and paste the code below. The
                                extension will open BookingExperts so you can sign in.
                            </template>
                        </DialogDescription>
                    </DialogHeader>

                    <div v-if="pairing" class="flex flex-col gap-4">
                        <div class="bg-muted flex items-center justify-between gap-3 rounded-md p-3 font-mono text-sm">
                            <span class="break-all">{{ pairing.pasteCode }}…</span>
                            <Button variant="ghost" size="icon" @click="copyToken" title="Copy full token">
                                <Copy class="size-4" />
                            </Button>
                        </div>

                        <div class="flex items-center gap-2 text-sm">
                            <template v-if="pairing.status === 'waiting'">
                                <Loader2 class="size-4 animate-spin" />
                                <template v-if="pairing.mode === 'relink'">
                                    Waiting for the extension to deliver fresh cookies…
                                </template>
                                <template v-else>
                                    Waiting for the extension to deliver cookies…
                                </template>
                            </template>
                            <template v-else-if="pairing.status === 'ready'">
                                <CheckCircle2 class="text-success size-4" />
                                <template v-if="pairing.mode === 'relink'">
                                    Re-authenticated{{ pairing.sessionPreview?.account_email ? ` as ${pairing.sessionPreview.account_email}` : '' }}.
                                </template>
                                <template v-else>
                                    Authenticated{{ pairing.sessionPreview?.account_email ? ` as ${pairing.sessionPreview.account_email}` : '' }}.
                                </template>
                            </template>
                            <template v-else-if="pairing.status === 'expired'">
                                <XCircle class="text-warning size-4" />
                                Code expired. Generate a new one.
                            </template>
                            <template v-else-if="pairing.status === 'unknown'">
                                <XCircle class="text-destructive size-4" />
                                Unknown error. Try again.
                            </template>
                        </div>

                        <p class="text-muted-foreground text-xs">
                            Code expires {{ new Date(pairing.expiresAt).toLocaleTimeString() }}. Cookies
                            are encrypted at rest.
                        </p>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" @click="dialogOpen = false">Close</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
