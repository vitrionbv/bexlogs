<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    CheckCircle2,
    Cookie,
    Cpu,
    Database,
    HardDrive,
    KeyRound,
    Loader2,
    MemoryStick,
    PauseCircle,
    Plug,
    Plus,
    Server,
} from 'lucide-vue-next';
import { computed, defineComponent, h, ref } from 'vue';
import type { Component } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useChannel } from '@/composables/useRealtime';

type DashSummary = {
    counts: { queued: number; running: number; completed_24h: number; failed: number };
    sessions_total: number;
    sessions_active: number;
    subscriptions_total: number;
    subscriptions_auto: number;
    logs_total: number;
    recent: {
        id: number;
        subscription_id: string;
        subscription_name: string;
        status: 'queued' | 'running' | 'completed' | 'failed' | 'cancelled';
        created_at: string;
        completed_at: string | null;
        error: string | null;
        // commit 642768b: Jobs UI surfaces rows_inserted (genuinely-new
        // rows that survived the (page_id, content_hash) unique index)
        // as the primary "useful work" count. The legacy stats.rows
        // (pages_processed × BATCH_SIZE) is no longer read.
        rows_inserted: number | null;
        rows_received: number | null;
    }[];
};

type SessionRow = {
    id: number;
    environment: 'production' | 'staging';
    account_email: string | null;
    account_name: string | null;
    captured_at: string | null;
    last_validated_at: string | null;
    expired_at: string | null;
};

type PageRow = {
    id: number;
    subscription_id: string;
    subscription_name: string;
    environment: string | null;
    auto_scrape: boolean;
    logs_count: number;
    last_scraped_at: string | null;
};

type ServerStats = {
    hostname: string | null;
    cpu: { percent: number | null; cores: number };
    memory: { total: number; used: number; available: number; percent: number } | null;
    disk: { total: number; used: number; free: number; percent: number } | null;
    load: { 1: number; 5: number; 15: number } | null;
    uptime_seconds: number | null;
    generated_at: string;
};

const props = defineProps<{
    summary: DashSummary;
    sessions: SessionRow[];
    pages: PageRow[];
    serverStats: ServerStats | null;
}>();

const liveStats = ref<ServerStats | null>(props.serverStats);

useChannel('server-stats', {
    'server-stats-updated': (payload) => {
        liveStats.value = payload as unknown as ServerStats;
    },
});

function formatBytes(bytes: number | null | undefined): string {
    if (bytes === null || bytes === undefined || bytes <= 0) {
        return '—';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const exp = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    const value = bytes / Math.pow(1024, exp);

    return `${value < 10 ? value.toFixed(1) : Math.round(value)} ${units[exp]}`;
}

function formatUptime(seconds: number | null | undefined): string {
    if (!seconds || seconds <= 0) {
        return '—';
    }

    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);

    if (days > 0) {
        return `${days}d ${hours}h`;
    }

    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    }

    return `${minutes}m`;
}

function pctTone(pct: number | null | undefined): string {
    if (pct === null || pct === undefined) {
        return 'bg-muted-foreground/40';
    }

    if (pct >= 90) {
        return 'bg-destructive';
    }

    if (pct >= 75) {
        return 'bg-warning';
    }

    return 'bg-primary';
}

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }] },
});

const STATUS_META = {
    queued: { variant: 'secondary' as const, icon: PauseCircle, label: 'Queued' },
    running: { variant: 'default' as const, icon: Loader2, label: 'Running' },
    completed: { variant: 'success' as const, icon: CheckCircle2, label: 'Completed' },
    failed: { variant: 'destructive' as const, icon: AlertTriangle, label: 'Failed' },
    cancelled: { variant: 'outline' as const, icon: PauseCircle, label: 'Cancelled' },
};

function relative(iso: string | null): string {
    if (!iso) {
return 'never';
}

    const diff = Date.now() - new Date(iso).getTime();
    const sec = Math.max(1, Math.round(diff / 1000));

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

    return `${Math.round(hr / 24)}d ago`;
}

const healthBadge = computed(() => {
    if (props.summary.sessions_active === 0) {
        return { variant: 'destructive' as const, label: 'No active sessions' };
    }

    if (props.summary.counts.failed > 0) {
        return { variant: 'warning' as const, label: `${props.summary.counts.failed} failing` };
    }

    return { variant: 'success' as const, label: 'Healthy' };
});

const StatCard = defineComponent({
    name: 'StatCard',
    props: {
        label: { type: String, required: true },
        value: { type: [Number, String], required: true },
        sub: { type: String, default: '' },
        icon: { type: Object as () => Component, required: true },
        href: { type: String, default: '' },
        tone: { type: String, default: 'default' }, // default | warning | destructive
    },
    setup(props) {
        const toneClass = {
            default: 'text-foreground',
            warning: 'text-warning',
            destructive: 'text-destructive',
        }[props.tone] ?? 'text-foreground';

        return () => {
            const inner = h(
                'div',
                {
                    class:
                        'border-border bg-card hover:border-primary/40 flex flex-col gap-1 rounded-lg border p-4 transition-colors',
                },
                [
                    h('div', { class: 'flex items-center justify-between' }, [
                        h('span', { class: 'text-muted-foreground text-xs uppercase tracking-wide' }, props.label),
                        h(props.icon, { class: 'text-muted-foreground size-4' }),
                    ]),
                    h(
                        'span',
                        { class: `text-2xl font-semibold tabular-nums ${toneClass}` },
                        String(props.value).toLocaleString?.() ?? String(props.value),
                    ),
                    props.sub
                        ? h('span', { class: 'text-muted-foreground text-xs' }, props.sub)
                        : null,
                ],
            );

            return props.href
                ? h(Link, { href: props.href, class: 'block' }, () => inner)
                : inner;
        };
    },
});
</script>

<template>
    <div>
        <Head title="Dashboard" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Welcome back</h1>
                    <p class="text-muted-foreground text-sm">Live overview of sessions, scraping and logs.</p>
                </div>
                <Badge :variant="healthBadge.variant" class="text-sm">{{ healthBadge.label }}</Badge>
            </div>

            <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label="Active sessions"
                    :value="summary.sessions_active"
                    :sub="`of ${summary.sessions_total} stored`"
                    :icon="KeyRound"
                    href="/authenticate"
                    :tone="summary.sessions_active === 0 ? 'destructive' : 'default'"
                />
                <StatCard
                    label="Subscriptions"
                    :value="summary.subscriptions_total"
                    :sub="`${summary.subscriptions_auto} auto-scraping`"
                    :icon="Database"
                    href="/manage"
                />
                <StatCard
                    label="Jobs in queue"
                    :value="summary.counts.queued + summary.counts.running"
                    :sub="`${summary.counts.completed_24h} done · 24h`"
                    :icon="Activity"
                    href="/jobs"
                    :tone="summary.counts.failed > 0 ? 'warning' : 'default'"
                />
                <StatCard
                    label="Total log entries"
                    :value="summary.logs_total"
                    sub="across all logs"
                    :icon="Cookie"
                    href="/logs"
                />
            </section>

            <section v-if="liveStats">
                <Card>
                    <CardHeader class="flex-row items-center justify-between gap-2">
                        <div>
                            <CardTitle class="flex items-center gap-2">
                                <Server class="size-4" />
                                Server vitals
                            </CardTitle>
                            <CardDescription>
                                Live host CPU, memory and disk usage. Updates every five seconds over WebSocket.
                            </CardDescription>
                        </div>
                        <div class="flex items-center gap-2 text-xs">
                            <span
                                class="bg-success inline-block size-2 animate-pulse rounded-full"
                                aria-hidden="true"
                            />
                            <span class="text-muted-foreground">
                                {{ liveStats.hostname ?? 'host' }} · uptime {{ formatUptime(liveStats.uptime_seconds) }}
                            </span>
                        </div>
                    </CardHeader>
                    <CardContent class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div class="border-border bg-card flex flex-col gap-2 rounded-lg border p-4">
                            <div class="flex items-center justify-between">
                                <span class="text-muted-foreground flex items-center gap-2 text-xs uppercase tracking-wide">
                                    <Cpu class="size-3.5" /> CPU
                                </span>
                                <span class="text-muted-foreground text-xs">{{ liveStats.cpu.cores }} cores</span>
                            </div>
                            <span class="text-3xl font-semibold tabular-nums">
                                {{ liveStats.cpu.percent !== null ? liveStats.cpu.percent.toFixed(1) : '—' }}<span class="text-muted-foreground text-base">%</span>
                            </span>
                            <div class="bg-muted h-1.5 overflow-hidden rounded-full">
                                <div
                                    class="h-full transition-all duration-700"
                                    :class="pctTone(liveStats.cpu.percent)"
                                    :style="{ width: `${Math.min(100, Math.max(0, liveStats.cpu.percent ?? 0))}%` }"
                                />
                            </div>
                            <div class="text-muted-foreground flex justify-between text-xs">
                                <span>load 1m</span>
                                <span class="tabular-nums">{{ liveStats.load?.['1']?.toFixed(2) ?? '—' }}</span>
                            </div>
                        </div>

                        <div class="border-border bg-card flex flex-col gap-2 rounded-lg border p-4">
                            <div class="flex items-center justify-between">
                                <span class="text-muted-foreground flex items-center gap-2 text-xs uppercase tracking-wide">
                                    <MemoryStick class="size-3.5" /> Memory
                                </span>
                                <span class="text-muted-foreground text-xs tabular-nums">
                                    {{ formatBytes(liveStats.memory?.used) }} / {{ formatBytes(liveStats.memory?.total) }}
                                </span>
                            </div>
                            <span class="text-3xl font-semibold tabular-nums">
                                {{ liveStats.memory ? liveStats.memory.percent.toFixed(1) : '—' }}<span class="text-muted-foreground text-base">%</span>
                            </span>
                            <div class="bg-muted h-1.5 overflow-hidden rounded-full">
                                <div
                                    class="h-full transition-all duration-700"
                                    :class="pctTone(liveStats.memory?.percent)"
                                    :style="{ width: `${Math.min(100, Math.max(0, liveStats.memory?.percent ?? 0))}%` }"
                                />
                            </div>
                            <div class="text-muted-foreground flex justify-between text-xs">
                                <span>available</span>
                                <span class="tabular-nums">{{ formatBytes(liveStats.memory?.available) }}</span>
                            </div>
                        </div>

                        <div class="border-border bg-card flex flex-col gap-2 rounded-lg border p-4">
                            <div class="flex items-center justify-between">
                                <span class="text-muted-foreground flex items-center gap-2 text-xs uppercase tracking-wide">
                                    <HardDrive class="size-3.5" /> Disk
                                </span>
                                <span class="text-muted-foreground text-xs tabular-nums">
                                    {{ formatBytes(liveStats.disk?.used) }} / {{ formatBytes(liveStats.disk?.total) }}
                                </span>
                            </div>
                            <span class="text-3xl font-semibold tabular-nums">
                                {{ liveStats.disk ? liveStats.disk.percent.toFixed(1) : '—' }}<span class="text-muted-foreground text-base">%</span>
                            </span>
                            <div class="bg-muted h-1.5 overflow-hidden rounded-full">
                                <div
                                    class="h-full transition-all duration-700"
                                    :class="pctTone(liveStats.disk?.percent)"
                                    :style="{ width: `${Math.min(100, Math.max(0, liveStats.disk?.percent ?? 0))}%` }"
                                />
                            </div>
                            <div class="text-muted-foreground flex justify-between text-xs">
                                <span>free</span>
                                <span class="tabular-nums">{{ formatBytes(liveStats.disk?.free) }}</span>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </section>

            <section class="grid gap-4 lg:grid-cols-3">
                <Card class="lg:col-span-2">
                    <CardHeader class="flex-row items-center justify-between gap-2">
                        <div>
                            <CardTitle>Recent activity</CardTitle>
                            <CardDescription>Latest scrape jobs from across all subscriptions.</CardDescription>
                        </div>
                        <Button as-child variant="outline" size="sm">
                            <Link href="/jobs">View all</Link>
                        </Button>
                    </CardHeader>
                    <CardContent class="space-y-1">
                        <p v-if="!summary.recent.length" class="text-muted-foreground text-sm">
                            No jobs yet. Add a subscription on the Manage page to start scraping.
                        </p>
                        <Link
                            v-for="job in summary.recent"
                            :key="job.id"
                            :href="`/jobs?focus=${job.id}`"
                            class="hover:bg-accent flex items-center justify-between gap-3 rounded-md px-2 py-1.5 transition-colors"
                        >
                            <div class="flex min-w-0 items-center gap-2">
                                <component
                                    :is="STATUS_META[job.status].icon"
                                    :class="[
                                        'size-4 shrink-0',
                                        job.status === 'running' && 'animate-spin',
                                        job.status === 'failed' && 'text-destructive',
                                        job.status === 'completed' && 'text-success',
                                    ]"
                                />
                                <span class="truncate text-sm">{{ job.subscription_name }}</span>
                            </div>
                            <div class="flex items-center gap-3 text-xs">
                                <span v-if="job.rows_inserted !== null" class="text-muted-foreground tabular-nums">
                                    {{ job.rows_inserted }} rows
                                </span>
                                <Badge :variant="STATUS_META[job.status].variant" class="capitalize">
                                    {{ STATUS_META[job.status].label }}
                                </Badge>
                                <span class="text-muted-foreground w-16 text-right tabular-nums">
                                    {{ relative(job.completed_at ?? job.created_at) }}
                                </span>
                            </div>
                        </Link>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="flex-row items-center justify-between gap-2">
                        <div>
                            <CardTitle>Sessions</CardTitle>
                            <CardDescription>BookingExperts logins captured by the extension.</CardDescription>
                        </div>
                        <Button as-child variant="outline" size="sm">
                            <Link href="/authenticate">Manage</Link>
                        </Button>
                    </CardHeader>
                    <CardContent>
                        <Link
                            v-if="!sessions.length"
                            href="/authenticate"
                            class="border-border hover:bg-accent flex flex-col items-center gap-2 rounded-md border border-dashed p-6 text-center text-sm"
                        >
                            <Plug class="text-muted-foreground size-6" />
                            <p class="font-medium">Connect your account</p>
                            <p class="text-muted-foreground text-xs">
                                Install the extension and link this instance.
                            </p>
                        </Link>
                        <ul v-else class="divide-border divide-y">
                            <li
                                v-for="s in sessions"
                                :key="s.id"
                                class="flex items-start justify-between gap-2 py-2"
                            >
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium">
                                        {{ s.account_name ?? s.account_email ?? 'Unknown' }}
                                    </p>
                                    <p class="text-muted-foreground truncate text-xs">
                                        {{ s.account_email ?? '—' }}
                                    </p>
                                </div>
                                <div class="flex shrink-0 flex-col items-end gap-1 text-[10px]">
                                    <Badge
                                        :variant="s.expired_at ? 'destructive' : 'success'"
                                        class="capitalize"
                                    >
                                        {{ s.expired_at ? 'expired' : s.environment }}
                                    </Badge>
                                    <span class="text-muted-foreground">
                                        seen {{ relative(s.last_validated_at ?? s.captured_at) }}
                                    </span>
                                </div>
                            </li>
                        </ul>
                    </CardContent>
                </Card>
            </section>

            <section>
                <Card>
                    <CardHeader class="flex-row items-center justify-between gap-2">
                        <div>
                            <CardTitle>Recently watched logs</CardTitle>
                            <CardDescription>Subscriptions producing the most recent log activity.</CardDescription>
                        </div>
                        <Button as-child variant="outline" size="sm">
                            <Link href="/manage">
                                <Plus class="mr-1 size-4" /> Add subscription
                            </Link>
                        </Button>
                    </CardHeader>
                    <CardContent>
                        <p v-if="!pages.length" class="text-muted-foreground text-sm">
                            No subscriptions yet. Open Manage to add one.
                        </p>
                        <div v-else class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            <Link
                                v-for="p in pages"
                                :key="p.id"
                                :href="`/logs/${p.id}`"
                                class="border-border bg-card hover:border-primary/40 hover:bg-accent block rounded-md border p-3 transition-colors"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <p class="truncate text-sm font-medium">{{ p.subscription_name }}</p>
                                    <Badge variant="outline" class="text-[10px] uppercase">{{ p.environment ?? '—' }}</Badge>
                                </div>
                                <div class="text-muted-foreground mt-2 flex items-center justify-between text-xs">
                                    <span>{{ p.logs_count.toLocaleString() }} entries</span>
                                    <span>{{ relative(p.last_scraped_at) }}</span>
                                </div>
                            </Link>
                        </div>
                    </CardContent>
                </Card>
            </section>
        </div>
    </div>
</template>

