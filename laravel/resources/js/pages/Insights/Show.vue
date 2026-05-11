<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Clock,
    Gauge,
    PauseCircle,
    Play,
    Radio,
} from 'lucide-vue-next';
import {
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    type ChartData,
    DoughnutController,
    Legend,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Title,
    Tooltip as ChartTooltip,
} from 'chart.js';
import { Bar, Doughnut, Line } from 'vue-chartjs';
import { computed, onMounted, ref } from 'vue';
import HealthBadge from '@/components/HealthBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useUserChannel } from '@/composables/useRealtime';

// Chart.js v4 ships every controller as a tree-shakable module — register
// only the ones we use. CategoryScale/LinearScale handle the day axis on
// the bar chart, BarController/LineController/DoughnutController are the
// three chart kinds, ArcElement is needed by the donut, PointElement is
// needed by the line, and BarElement is needed by the stacked bar.
//
// We deliberately skip TimeScale — it requires an external date adapter
// (chartjs-adapter-date-fns or moment) which is overkill for our small
// "last 7/30/90 days" windows. Pre-formatted day labels on a
// CategoryScale render identically and dodge the adapter dependency.
Chart.register(
    LineController,
    BarController,
    DoughnutController,
    CategoryScale,
    LinearScale,
    LineElement,
    PointElement,
    BarElement,
    ArcElement,
    ChartTooltip,
    Legend,
    Title,
);

interface HealthInfo {
    score: number;
    label: 'healthy' | 'degraded' | 'unhealthy';
    components: {
        success_rate: number;
        freshness: number;
        stability: number;
    };
    sample_size: number;
    last_success_at: string | null;
}

interface BaselineInfo {
    duration_p50: number | null;
    duration_p95: number | null;
    duration_p99: number | null;
    rows_inserted_p50: number | null;
    rows_inserted_p95: number | null;
    rows_inserted_p99: number | null;
    sample_size: number;
    computed_at: string | null;
}

interface SeriesPoint {
    id: number;
    t: string;
    rows_inserted: number;
    duration_ms: number;
}

interface StatusMixDay {
    day: string;
    completed: number;
    failed: number;
    cancelled: number;
    queued: number;
    running: number;
}

interface StopReasonSlice {
    reason: string;
    count: number;
}

interface RecentJob {
    id: number;
    status: 'queued' | 'running' | 'completed' | 'failed' | 'cancelled';
    created_at: string | null;
    completed_at: string | null;
    rows_inserted: number | null;
    duration_ms: number | null;
    stop_reason: string | null;
}

interface RecentLog {
    id: number;
    page_id: number;
    timestamp: string;
    type: string;
    action: string;
    method: string;
    status: string | null;
}

const props = defineProps<{
    subscription: {
        id: string;
        name: string;
        environment: string;
        scrape_interval_minutes: number;
        last_scraped_at: string | null;
    };
    health: HealthInfo;
    baseline: BaselineInfo | null;
    range: 7 | 30 | 90;
    series: { points: SeriesPoint[] };
    statusMix: StatusMixDay[];
    stopReasons: StopReasonSlice[];
    recentJobs: RecentJob[];
    recentLogs: RecentLog[];
    page_id: number | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Manage', href: '/manage' },
            { title: 'Insights', href: '#' },
        ],
    },
});

const RANGE_OPTIONS = [
    { value: 7, label: '7d' },
    { value: 30, label: '30d' },
    { value: 90, label: '90d' },
] as const;

function changeRange(next: 7 | 30 | 90): void {
    router.get(
        `/subscriptions/${props.subscription.id}/insights`,
        { range: next },
        { preserveScroll: true, preserveState: false },
    );
}

// Live-tail state for the embedded compact panel. Mirrors the dedicated
// /logs/live page logic but capped to 10 rows so the Insights page
// stays visually balanced. A LogBatchInserted event whose page_id
// matches our subscription's page triggers a since-fetch and prepend.
const liveLogs = ref<RecentLog[]>([...props.recentLogs]);

if (props.page_id !== null) {
    const myPageId = props.page_id;

    useUserChannel({
        'log-batch-inserted': async (payload) => {
            const eventPageId = (payload as { page_id?: number }).page_id;

            if (eventPageId !== myPageId) {
                return;
            }

            const cursor = liveLogs.value.length > 0 ? liveLogs.value[0].id : 0;

            try {
                const res = await fetch(`/logs/live/since/${myPageId}?since=${cursor}`, {
                    headers: { Accept: 'application/json' },
                });

                if (!res.ok) return;

                const data = (await res.json()) as { rows: RecentLog[] };
                // The since endpoint returns rows in DESC id order, so a
                // direct prepend keeps the visible list newest-first.
                // The 10-row cap is enforced after the merge.
                liveLogs.value = [...data.rows, ...liveLogs.value].slice(0, 10);
            } catch {
                // Network errors are non-fatal — the next batch will
                // catch us up.
            }
        },
    });
}

// ─── Charts ────────────────────────────────────────────────────────────────
//
// Chart.js options + data are computed from props. The line and bar
// charts use the day-axis for X and per-job points for Y; the donut
// is just a count distribution.

const lineCommonOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: true, position: 'bottom' as const },
    },
    scales: {
        x: {
            grid: { display: false },
            ticks: {
                // Compact day labels: only show every Nth tick on long
                // ranges so the axis doesn't crowd. Chart.js's auto
                // skip handles this when maxTicksLimit is set.
                maxTicksLimit: 10,
            },
        },
        y: { beginAtZero: true },
    },
};

// Format an ISO timestamp into a short "MMM dd HH:mm" label suitable
// for a CategoryScale axis. We sacrifice the auto-zoom behaviour of
// the time scale in exchange for not needing chartjs-adapter-date-fns
// (or moment) as a runtime dependency.
function shortLabel(iso: string): string {
    const d = new Date(iso);

    if (Number.isNaN(d.getTime())) return iso;

    return d.toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

const seriesLabels = computed(() => props.series.points.map((p) => shortLabel(p.t)));

const rowsChartData = computed<ChartData<'line'>>(() => ({
    labels: seriesLabels.value,
    datasets: [
        {
            label: 'Rows inserted',
            data: props.series.points.map((p) => p.rows_inserted),
            borderColor: '#3b82f6',
            backgroundColor: '#3b82f6',
            tension: 0.2,
            pointRadius: 2,
        },
    ],
}));

const durationChartData = computed<ChartData<'line'>>(() => {
    const datasets: ChartData<'line'>['datasets'] = [
        {
            label: 'Duration (ms)',
            data: props.series.points.map((p) => p.duration_ms),
            borderColor: '#10b981',
            backgroundColor: '#10b981',
            tension: 0.2,
            pointRadius: 2,
        },
    ];

    // p50 / p95 reference bands when a baseline exists. Each band is
    // rendered as a flat dataset (every Y is the baseline value) so
    // it forms a horizontal line across the chart at the right
    // height. Skipped when the baseline is missing.
    const baseline = props.baseline;
    const points = props.series.points;

    if (
        baseline !== null
        && baseline.duration_p50 !== null
        && baseline.duration_p95 !== null
        && points.length >= 2
    ) {
        datasets.push(
            {
                label: 'p50 baseline',
                data: points.map(() => baseline.duration_p50 as number),
                borderColor: '#94a3b8',
                borderDash: [4, 4],
                pointRadius: 0,
                tension: 0,
            },
            {
                label: 'p95 baseline',
                data: points.map(() => baseline.duration_p95 as number),
                borderColor: '#f59e0b',
                borderDash: [4, 4],
                pointRadius: 0,
                tension: 0,
            },
        );
    }

    return {
        labels: seriesLabels.value,
        datasets,
    };
});

const statusMixChartData = computed(() => ({
    labels: props.statusMix.map((d) => d.day),
    datasets: [
        {
            label: 'Completed',
            data: props.statusMix.map((d) => d.completed),
            backgroundColor: '#10b981',
        },
        {
            label: 'Failed',
            data: props.statusMix.map((d) => d.failed),
            backgroundColor: '#ef4444',
        },
        {
            label: 'Cancelled',
            data: props.statusMix.map((d) => d.cancelled),
            backgroundColor: '#94a3b8',
        },
    ],
}));

const statusMixOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom' as const } },
    scales: {
        x: { stacked: true, grid: { display: false } },
        y: { stacked: true, beginAtZero: true },
    },
};

// Donut palette — picked to give visual distinction across the eleven
// possible stop_reasons. Keys we don't have an explicit colour for
// fall back to a muted slate.
const STOP_REASON_COLORS: Record<string, string> = {
    duplicate_detection: '#22c55e',
    caught_up: '#10b981',
    pagination_limit: '#3b82f6',
    time_limit: '#6366f1',
    pagination_error: '#f97316',
    token_missing: '#f97316',
    unparseable: '#dc2626',
    runaway_safety: '#dc2626',
    empty_window: '#0ea5e9',
    session_expired: '#a21caf',
    worker_reaped: '#f43f5e',
};

const stopReasonChartData = computed(() => ({
    labels: props.stopReasons.map((s) => s.reason.replace(/_/g, ' ')),
    datasets: [
        {
            data: props.stopReasons.map((s) => s.count),
            backgroundColor: props.stopReasons.map(
                (s) => STOP_REASON_COLORS[s.reason] ?? '#64748b',
            ),
        },
    ],
}));

const stopReasonOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom' as const } },
};

// ─── Helpers ───────────────────────────────────────────────────────────────

function formatRelative(iso: string | null): string {
    if (!iso) return '—';

    const ms = Date.now() - new Date(iso).getTime();

    if (!Number.isFinite(ms) || ms < 0) return 'just now';

    const seconds = Math.floor(ms / 1000);

    if (seconds < 60) return `${seconds}s ago`;

    const minutes = Math.floor(seconds / 60);

    if (minutes < 60) return `${minutes}m ago`;

    const hours = Math.floor(minutes / 60);

    if (hours < 48) return `${hours}h ago`;

    const days = Math.floor(hours / 24);

    return `${days}d ago`;
}

function formatDuration(ms: number | null): string {
    if (ms === null || !Number.isFinite(ms)) return '—';

    if (ms < 1000) return `${ms} ms`;

    const seconds = ms / 1000;

    if (seconds < 60) return `${seconds.toFixed(1)} s`;

    const minutes = Math.floor(seconds / 60);
    const tail = (seconds % 60).toFixed(0);

    return `${minutes}m ${tail}s`;
}

const STATUS_LABELS: Record<RecentJob['status'], string> = {
    queued: 'Queued',
    running: 'Running',
    completed: 'Completed',
    failed: 'Failed',
    cancelled: 'Cancelled',
};

const STATUS_VARIANTS: Record<RecentJob['status'], 'default' | 'success' | 'destructive' | 'secondary' | 'outline'> = {
    queued: 'secondary',
    running: 'default',
    completed: 'success',
    failed: 'destructive',
    cancelled: 'outline',
};

const hasJobs = computed(() => props.recentJobs.length > 0);
const hasSeries = computed(() => props.series.points.length > 0);
const hasStatusMix = computed(() => props.statusMix.length > 0);
const hasStopReasons = computed(() => props.stopReasons.length > 0);

onMounted(() => {
    // Empty hook — chart.js handles its own lifecycle via vue-chartjs.
});
</script>

<template>
    <Head :title="`Insights · ${subscription.name}`" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-4 p-4 md:p-6">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="space-y-1">
                <Button as-child variant="ghost" size="sm" class="-ml-2">
                    <Link href="/manage">
                        <ArrowLeft class="mr-1 size-4" /> Back to Manage
                    </Link>
                </Button>
                <h1 class="flex items-center gap-2 text-2xl font-semibold tracking-tight">
                    <HealthBadge :health="health" />
                    {{ subscription.name }}
                </h1>
                <p class="text-muted-foreground text-sm">
                    <span class="font-mono">{{ subscription.id }}</span>
                    · <Badge variant="outline" class="px-1 py-0 text-[10px]">{{ subscription.environment }}</Badge>
                    · interval {{ subscription.scrape_interval_minutes }}m
                    · last scrape {{ formatRelative(subscription.last_scraped_at) }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <div class="border-border bg-background flex rounded-md border p-0.5">
                    <button
                        v-for="opt in RANGE_OPTIONS"
                        :key="opt.value"
                        type="button"
                        class="rounded px-3 py-1 text-sm transition-colors"
                        :class="
                            range === opt.value
                                ? 'bg-muted text-foreground'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="changeRange(opt.value)"
                    >
                        {{ opt.label }}
                    </button>
                </div>
            </div>
        </header>

        <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="flex items-center gap-2 text-sm font-medium">
                        <Gauge class="text-muted-foreground size-4" />
                        Health score
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <p class="text-2xl font-semibold tabular-nums">
                        {{ Math.round(health.score * 100) }}%
                    </p>
                    <p class="text-muted-foreground text-xs capitalize">
                        {{ health.label }} · {{ health.sample_size }} jobs
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="flex items-center gap-2 text-sm font-medium">
                        <Clock class="text-muted-foreground size-4" />
                        Duration p95
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <p class="text-2xl font-semibold tabular-nums">
                        {{ baseline?.duration_p95 ? formatDuration(Math.round(baseline.duration_p95)) : '—' }}
                    </p>
                    <p class="text-muted-foreground text-xs">
                        p50: {{ baseline?.duration_p50 ? formatDuration(Math.round(baseline.duration_p50)) : '—' }}
                        · p99: {{ baseline?.duration_p99 ? formatDuration(Math.round(baseline.duration_p99)) : '—' }}
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="flex items-center gap-2 text-sm font-medium">
                        <Play class="text-muted-foreground size-4" />
                        Rows p95
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <p class="text-2xl font-semibold tabular-nums">
                        {{ baseline?.rows_inserted_p95 !== undefined && baseline?.rows_inserted_p95 !== null
                            ? Math.round(baseline.rows_inserted_p95).toLocaleString()
                            : '—' }}
                    </p>
                    <p class="text-muted-foreground text-xs">
                        per scrape · sample {{ baseline?.sample_size ?? 0 }}
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2">
                    <CardTitle class="flex items-center gap-2 text-sm font-medium">
                        <PauseCircle class="text-muted-foreground size-4" />
                        Baseline age
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <p class="text-2xl font-semibold tabular-nums">
                        {{ formatRelative(baseline?.computed_at ?? null) }}
                    </p>
                    <p class="text-muted-foreground text-xs">
                        recomputed nightly
                    </p>
                </CardContent>
            </Card>
        </section>

        <section class="grid gap-4 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>Rows inserted per scrape</CardTitle>
                    <CardDescription>
                        Each point is one completed scrape; window {{ range }}d.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="h-64">
                        <Line
                            v-if="hasSeries"
                            :data="rowsChartData"
                            :options="lineCommonOptions"
                        />
                        <p v-else class="text-muted-foreground py-8 text-center text-sm">
                            No completed scrapes in this window.
                        </p>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Duration</CardTitle>
                    <CardDescription>
                        Per-scrape duration with rolling p50/p95 bands.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="h-64">
                        <Line
                            v-if="hasSeries"
                            :data="durationChartData"
                            :options="lineCommonOptions"
                        />
                        <p v-else class="text-muted-foreground py-8 text-center text-sm">
                            No completed scrapes in this window.
                        </p>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Success / fail mix</CardTitle>
                    <CardDescription>
                        Daily breakdown of job outcomes.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="h-64">
                        <Bar
                            v-if="hasStatusMix"
                            :data="statusMixChartData"
                            :options="statusMixOptions"
                        />
                        <p v-else class="text-muted-foreground py-8 text-center text-sm">
                            No jobs in this window.
                        </p>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Stop reasons</CardTitle>
                    <CardDescription>
                        Why scrapes ended in this window.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="h-64">
                        <Doughnut
                            v-if="hasStopReasons"
                            :data="stopReasonChartData"
                            :options="stopReasonOptions"
                        />
                        <p v-else class="text-muted-foreground py-8 text-center text-sm">
                            No stop reasons recorded.
                        </p>
                    </div>
                </CardContent>
            </Card>
        </section>

        <section class="grid gap-4 lg:grid-cols-3">
            <Card class="lg:col-span-2">
                <CardHeader class="flex-row items-center justify-between">
                    <div>
                        <CardTitle>Recent jobs</CardTitle>
                        <CardDescription>
                            Click through to the Jobs detail dialog for full stats.
                        </CardDescription>
                    </div>
                    <Button as-child variant="outline" size="sm">
                        <Link :href="`/jobs?subscription=${subscription.id}`">
                            View all
                        </Link>
                    </Button>
                </CardHeader>
                <CardContent class="space-y-1">
                    <p v-if="!hasJobs" class="text-muted-foreground text-sm">
                        No jobs in this window.
                    </p>
                    <Link
                        v-for="job in recentJobs"
                        :key="job.id"
                        :href="`/jobs?focus=${job.id}`"
                        class="hover:bg-accent flex items-center justify-between gap-3 rounded-md px-2 py-1.5 transition-colors"
                    >
                        <div class="flex min-w-0 items-center gap-2">
                            <Badge :variant="STATUS_VARIANTS[job.status]" class="capitalize">
                                {{ STATUS_LABELS[job.status] }}
                            </Badge>
                            <span class="text-muted-foreground truncate text-xs">
                                #{{ job.id }}
                                <span v-if="job.stop_reason"> · {{ job.stop_reason.replace(/_/g, ' ') }}</span>
                            </span>
                        </div>
                        <div class="flex items-center gap-3 text-xs">
                            <span v-if="job.rows_inserted !== null" class="text-muted-foreground tabular-nums">
                                {{ job.rows_inserted }} rows
                            </span>
                            <span v-if="job.duration_ms !== null" class="text-muted-foreground tabular-nums">
                                {{ formatDuration(job.duration_ms) }}
                            </span>
                            <span class="text-muted-foreground w-16 text-right tabular-nums">
                                {{ formatRelative(job.completed_at ?? job.created_at) }}
                            </span>
                        </div>
                    </Link>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="flex-row items-center justify-between">
                    <div>
                        <CardTitle class="flex items-center gap-2">
                            <Radio class="size-4" /> Live tail
                        </CardTitle>
                        <CardDescription>
                            Newest 10 log rows. Updates over WebSocket.
                        </CardDescription>
                    </div>
                </CardHeader>
                <CardContent class="space-y-1 font-mono text-xs">
                    <p v-if="!liveLogs.length" class="text-muted-foreground">
                        No log rows yet.
                    </p>
                    <div
                        v-for="log in liveLogs"
                        :key="log.id"
                        class="bg-muted/30 flex items-center gap-2 rounded px-2 py-1"
                    >
                        <span class="text-muted-foreground shrink-0">
                            {{ log.timestamp.split('T')[1]?.slice(0, 8) ?? log.timestamp }}
                        </span>
                        <span class="bg-background shrink-0 rounded px-1 text-[10px] uppercase">
                            {{ log.method }}
                        </span>
                        <span class="truncate">
                            {{ log.action }}
                        </span>
                        <span v-if="log.status" class="text-muted-foreground shrink-0">
                            {{ log.status }}
                        </span>
                    </div>
                </CardContent>
            </Card>
        </section>
    </div>
</template>
