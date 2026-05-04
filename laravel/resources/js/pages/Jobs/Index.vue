<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Activity,
    AlertTriangle,
    CheckCircle2,
    Loader2,
    Pause,
    RefreshCw,
    RotateCcw,
    Trash2,
    XCircle,
} from 'lucide-vue-next';
import { computed, defineComponent, h, onBeforeUnmount, onMounted, ref, watch } from 'vue';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useUserChannel } from '@/composables/useRealtime';

type StopReason =
    | 'duplicate_detection'
    | 'caught_up'
    | 'pagination_limit'
    | 'time_limit'
    | 'pagination_error'
    | 'token_missing'
    | 'unparseable'
    | 'token_echo'
    | 'runaway_safety'
    | 'empty_window'
    | 'session_expired'
    | 'worker_reaped';

type ScrapeJobStats = {
    /**
     * Legacy counter — empirically `pages_processed × BATCH_SIZE`, not a
     * real row count. Pre-`35f3948` the Jobs UI rendered this as "Rows"
     * which gave operators suspiciously round numbers (150, 100, 125 =
     * 6/4/5 pages × 25). We no longer write this on new completions
     * (see WorkerController::complete) and the UI no longer reads it
     * — the field is kept in the type only so old rows that already
     * carry it don't trip TypeScript's index-access strict check when
     * the JSON dump dialog renders the raw `stats` blob.
     */
    rows?: number;
    rows_received?: number;
    rows_inserted?: number;
    batches?: number;
    last_batch_at?: string;
    pages_processed?: number;
    pages?: number;
    duration_ms?: number;
    aborted_due_to_time?: boolean;
    early_stopped_due_to_duplicates?: boolean;
    total_duplicates?: number;
    /**
     * Diagnostic counter from `loadMoreWithTokenEchoRetry` (scraper).
     * Visible in the Stats JSON dialog; not surfaced as a badge — pure
     * observability for "is the helper firing / always exhausting / never
     * exhausting?" tuning.
     */
    token_echo_retries?: number;
    stop_reason?: StopReason;
    [key: string]: unknown;
};

type Job = {
    id: number;
    subscription_id: string;
    subscription_name: string;
    session_email: string | null;
    session_env: string | null;
    status: 'queued' | 'running' | 'completed' | 'failed' | 'cancelled';
    attempts: number;
    created_at: string;
    started_at: string | null;
    completed_at: string | null;
    last_heartbeat_at: string | null;
    error: string | null;
    stats: ScrapeJobStats | null;
    params: Record<string, unknown> | null;
};

type Paginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    links: { url: string | null; label: string; active: boolean }[];
    from: number | null;
    to: number | null;
    total: number;
};

const props = defineProps<{
    jobs: Paginator<Job>;
    filters: { status: string; subscription: string };
    statusCounts: Record<string, number>;
    subscriptions: { id: string; name: string }[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Jobs', href: '/jobs' }] },
});

const tableJobs = ref<Job[]>(props.jobs.data.map((j) => ({ ...j })));

watch(
    () => props.jobs.data,
    (d) => {
        tableJobs.value = d.map((j) => ({ ...j }));
    },
    { deep: true },
);

function mergeJobFromPayload(jobId: number, status: Job['status'] | undefined, stats: ScrapeJobStats) {
    const idx = tableJobs.value.findIndex((j) => j.id === jobId);

    if (idx === -1) {
        return false;
    }

    const cur = tableJobs.value[idx];
    tableJobs.value[idx] = {
        ...cur,
        ...(status ? { status } : {}),
        stats: { ...stats },
    };
    tableJobs.value = [...tableJobs.value];

    if (focused.value?.id === jobId) {
        focused.value = {
            ...focused.value,
            ...(status ? { status } : {}),
            stats: { ...stats },
        };
    }

    return true;
}

const statusFilter = ref(props.filters.status || 'all');
const subscriptionFilter = ref(props.filters.subscription || 'all');

function applyFilters() {
    router.get(
        '/jobs',
        {
            status: statusFilter.value === 'all' ? undefined : statusFilter.value,
            subscription: subscriptionFilter.value === 'all' ? undefined : subscriptionFilter.value,
        },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

const STATUS_META = {
    queued: { variant: 'secondary' as const, icon: Pause, label: 'Queued' },
    running: { variant: 'default' as const, icon: Loader2, label: 'Running' },
    completed: { variant: 'success' as const, icon: CheckCircle2, label: 'Completed' },
    failed: { variant: 'destructive' as const, icon: AlertTriangle, label: 'Failed' },
    cancelled: { variant: 'outline' as const, icon: XCircle, label: 'Cancelled' },
};

type StopReasonMeta = {
    label: string;
    variant: 'secondary' | 'warning' | 'destructive';
    description: string;
};

// Each entry maps the scraper-emitted enum value (`StopReason` in
// scraper/src/types.ts + `worker_reaped` from `ScrapeReapStale`) to the
// human-readable badge surface and a tooltip blurb. Variant choice:
//   - secondary  → expected, healthy terminations. `duplicate_detection`
//                  ("Caught up"), `caught_up` ("Caught up (live tip)"),
//                  and `empty_window` ("No activity") qualify.
//   - warning    → operator-visible caps that may need revisiting
//                  (pagination_limit, time_limit). The job completed; the
//                  cap just got in the way.
//   - destructive → hard failure paths. Anything in this bucket should be
//                   investigated: BE rate-limited us (pagination_error),
//                   pagination broke (token_missing / runaway_safety),
//                   the response shape changed (unparseable), our session
//                   died (session_expired), or the worker died
//                   (worker_reaped).
//
// `token_echo` lives here as a *legacy* fallback — the scraper retired
// the value when `loadMoreWithTokenEchoRetry` started absorbing echoes
// into the `caught_up` outcome. New jobs never emit it; old rows in the
// database still carry it and we don't backfill, so a labeled fallback
// keeps the page rendering. The "(legacy)" tag in the label tells the
// operator the row predates the retry layer.
const STOP_REASON_META: Record<StopReason, StopReasonMeta> = {
    duplicate_detection: {
        label: 'Caught up',
        variant: 'secondary',
        description: 'Pagination reached already-scraped rows — the healthy "we are done" signal.',
    },
    caught_up: {
        label: 'Caught up (live tip)',
        variant: 'secondary',
        description: 'Pagination caught up to the BookingExperts live log tip — either the token-echo retry helper exhausted its budget (see token_echo_retries in the stats dialog), or the eval-fallback path surfaced an echoed cursor on the very first load_more (fast path, retries=0). Both indicate "no new events yet"; new events arrive on the next scheduled scrape.',
    },
    empty_window: {
        label: 'No activity',
        variant: 'secondary',
        description: 'Initial page had zero rows and no next_token to chase — nothing to scrape.',
    },
    pagination_limit: {
        label: 'Pagination limit',
        variant: 'warning',
        description: 'Reached max_pages cap before catching up. Raise max_pages or wait for the next run to catch up via duplicate detection.',
    },
    time_limit: {
        label: 'Time limit',
        variant: 'warning',
        description: 'Wall-clock budget exceeded — job was aborted cleanly. Raise max_duration_minutes or split the time window.',
    },
    pagination_error: {
        label: 'Pagination error (422)',
        variant: 'destructive',
        description: 'BookingExperts returned 422 after retries — we are hitting them too hard. Lower MAX_CONCURRENT_SCRAPES.',
    },
    token_missing: {
        label: 'Missing pagination token',
        variant: 'destructive',
        description: 'BookingExperts stopped returning a next_token mid-scrape (next_token went null). Distinct from caught_up (where the same token came back). Investigate.',
    },
    unparseable: {
        label: 'Unparseable response',
        variant: 'destructive',
        description: 'load_more body could not be parsed and no fallback succeeded. BE response shape may have changed.',
    },
    token_echo: {
        label: 'Token echo (legacy)',
        variant: 'secondary',
        description: 'Legacy badge for jobs that ran before the token-echo retry helper landed. Equivalent to "Caught up (live tip)" — pagination handed back the same next_token. Newer runs no longer emit this value; this row is preserved for history.',
    },
    runaway_safety: {
        label: 'Runaway safety',
        variant: 'destructive',
        description: 'Hit the cap on consecutive zero-row pages. BE may be handing out an apparently-infinite quiet window.',
    },
    session_expired: {
        label: 'Session expired',
        variant: 'destructive',
        description: 'BookingExperts returned 401/403 — re-authenticate via the extension.',
    },
    worker_reaped: {
        label: 'Worker reaped',
        variant: 'destructive',
        description: 'Worker stopped heart-beating — the reaper failed the job.',
    },
};

// Returns the stop_reason key only when it's both present in stats AND
// known to STOP_REASON_META. Unknown values (notably legacy `natural_end`
// on rows persisted before the semantics revamp) fall through to `null`,
// which hides the secondary badge — the row still renders normally and
// the primary status badge ("Completed" / "Failed") carries the outcome.
// We deliberately don't backfill old rows; this guard keeps the page
// rendering for them without rewriting history.
function jobStopReason(job: Job): StopReason | null {
    if (job.status !== 'completed' && job.status !== 'failed') {
        return null;
    }

    const reason = job.stats?.stop_reason;

    if (typeof reason === 'string' && reason in STOP_REASON_META) {
        return reason as StopReason;
    }

    return null;
}

function refresh() {
    router.reload({ only: ['jobs', 'statusCounts', 'jobSummary'] });
}

function retry(job: Job) {
    router.post(`/jobs/${job.id}/retry`, {}, { preserveScroll: true, preserveState: true });
}

function cancel(job: Job) {
    router.post(`/jobs/${job.id}/cancel`, {}, { preserveScroll: true, preserveState: true });
}

function destroy(job: Job) {
    if (!confirm(`Delete job #${job.id}?`)) {
return;
}

    router.delete(`/jobs/${job.id}`, { preserveScroll: true, preserveState: true });
}

const purgeOpen = ref(false);
const purging = ref(false);

const purgeFailedOpen = ref(false);
const purgingFailed = ref(false);

const purgeableCount = computed(
    () =>
        (props.statusCounts.completed ?? 0) +
        (props.statusCounts.failed ?? 0) +
        (props.statusCounts.cancelled ?? 0),
);

function purgeFailed() {
    if (purgingFailed.value) {
        return;
    }

    purgingFailed.value = true;
    router.delete('/jobs/failed', {
        preserveScroll: true,
        onSuccess: () => {
            purgeFailedOpen.value = false;
        },
        onError: () => toast.error('Failed to remove failed jobs'),
        onFinish: () => {
            purgingFailed.value = false;
        },
    });
}

function purge() {
    if (purging.value) {
        return;
    }

    purging.value = true;
    router.delete('/jobs/old', {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Purged old jobs');
            purgeOpen.value = false;
        },
        onError: () => toast.error('Failed to purge jobs'),
        onFinish: () => {
            purging.value = false;
        },
    });
}

const focused = ref<Job | null>(null);

onMounted(() => {
    const focusId = new URL(window.location.href).searchParams.get('focus');

    if (focusId) {
        const match = props.jobs.data.find((j) => String(j.id) === focusId);

        if (match) {
focused.value = match;
}
    }
});

useUserChannel({
    'scrape-job-updated': (payload: Record<string, unknown>) => {
        const jobId = payload.job_id;
        const status = payload.status as Job['status'] | undefined;
        const rawStats = payload.stats;

        if (typeof jobId === 'number' && rawStats && typeof rawStats === 'object') {
            const merged = mergeJobFromPayload(jobId, status, rawStats as ScrapeJobStats);

            if (!merged) {
                refresh();
            }

            return;
        }

        if (typeof jobId === 'number' && status) {
            const idx = tableJobs.value.findIndex((j) => j.id === jobId);

            if (idx !== -1) {
                tableJobs.value[idx] = { ...tableJobs.value[idx], status };
                tableJobs.value = [...tableJobs.value];

                if (focused.value?.id === jobId) {
                    focused.value = { ...focused.value, status };
                }

                return;
            }
        }

        refresh();
    },
    'log-batch-inserted': () => {},
});

let safetyTimer: ReturnType<typeof setInterval> | null = null;
onMounted(() => {
    safetyTimer = setInterval(() => {
        if (document.visibilityState === 'visible') {
refresh();
}
    }, 60_000);
});
onBeforeUnmount(() => {
    if (safetyTimer) {
clearInterval(safetyTimer);
}
});

function fmt(iso: string | null): string {
    if (!iso) {
return '—';
}

    const d = new Date(iso);

    return d.toLocaleString();
}

function relative(iso: string | null): string {
    if (!iso) {
return '—';
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

function duration(job: Job): string {
    if (!job.started_at) {
return '—';
}

    const end = job.completed_at ?? job.last_heartbeat_at ?? new Date().toISOString();
    const ms = new Date(end).getTime() - new Date(job.started_at).getTime();
    const sec = Math.max(0, Math.round(ms / 1000));

    if (sec < 60) {
return `${sec}s`;
}

    const min = Math.floor(sec / 60);

    return `${min}m ${sec - min * 60}s`;
}

function compactCount(n: number): string {
    if (n >= 1_000_000) {
        return `${(n / 1_000_000).toFixed(1).replace(/\.0$/, '')}M`;
    }

    if (n >= 1000) {
        return `${(n / 1000).toFixed(1).replace(/\.0$/, '')}k`;
    }

    return String(n);
}

// `params.start_time` / `params.end_time` are the BookingExperts log-window
// bounds the scraper passes to /load_more_logs.js, NOT the scrape runtime.
// The naming overlap ("start"/"end") confused operators reading the raw
// JSON payload — this helper gives the dialog a labeled, human-readable
// summary above the JSON dump. Returns null when either field is missing
// or unparseable so the template hides the row entirely instead of
// rendering "Invalid Date → Invalid Date (NaNm)".
function formatLogWindow(
    params: Record<string, unknown> | null,
): { start: string; end: string; duration: string } | null {
    if (!params) {
        return null;
    }

    const start = params.start_time;
    const end = params.end_time;

    if (typeof start !== 'string' || typeof end !== 'string') {
        return null;
    }

    const startMs = Date.parse(start);
    const endMs = Date.parse(end);

    if (Number.isNaN(startMs) || Number.isNaN(endMs)) {
        return null;
    }

    return { start, end, duration: formatLogWindowDuration(endMs - startMs) };
}

// Human-readable d/h/m formatter for the log-window summary. Distinct from
// the row-level `duration()` (seconds + minutes for in-flight scrape
// runtimes) because log windows on the scheduled-scrape path are usually
// hours-to-days rather than seconds.
function formatLogWindowDuration(ms: number): string {
    if (ms <= 0) {
        return '0m';
    }

    const totalSec = Math.round(ms / 1000);
    const days = Math.floor(totalSec / 86_400);
    const hours = Math.floor((totalSec % 86_400) / 3600);
    const minutes = Math.floor((totalSec % 3600) / 60);

    const parts: string[] = [];

    if (days > 0) {
        parts.push(`${days}d`);
    }

    if (hours > 0) {
        parts.push(`${hours}h`);
    }

    if (minutes > 0 || parts.length === 0) {
        parts.push(`${minutes}m`);
    }

    return parts.join(' ');
}

type RowCounts = {
    received: number | null;
    inserted: number | null;
    duplicates: number;
};

// Pull the authoritative `rows_received` / `rows_inserted` /
// `total_duplicates` triplet off `scrape_jobs.stats`. Both the table
// cell and the detail dialog use this — the table renders the
// two-line received/inserted stack, the dialog renders an explicit
// three-field block above the raw JSON dump. Returns nullable
// inserted / received because in-flight jobs that haven't shipped a
// /batch POST yet have no stats. Duplicates fall back to 0 (the
// canonical value when there's nothing to dedup) rather than null
// because rendering "Duplicates: —" alongside concrete numbers reads
// as a bug; "Duplicates: 0" reads as the truth.
function jobRowCounts(job: Job): RowCounts {
    const s = job.stats;

    if (!s || typeof s !== 'object') {
        return { received: null, inserted: null, duplicates: 0 };
    }

    const ins = typeof s.rows_inserted === 'number' ? s.rows_inserted : null;
    const rec = typeof s.rows_received === 'number' ? s.rows_received : null;
    const dup = typeof s.total_duplicates === 'number'
        ? s.total_duplicates
        : (rec !== null && ins !== null ? Math.max(0, rec - ins) : 0);

    return { received: rec, inserted: ins, duplicates: dup };
}

const filterChips = computed(() =>
    (['all', 'queued', 'running', 'completed', 'failed', 'cancelled'] as const).map((status) => ({
        status,
        label: status === 'all' ? 'All' : STATUS_META[status].label,
        count:
            status === 'all'
                ? Object.values(props.statusCounts).reduce((a, b) => a + b, 0)
                : props.statusCounts[status] ?? 0,
    })),
);

const focusedLogWindow = computed(() => formatLogWindow(focused.value?.params ?? null));

const Field = defineComponent({
    name: 'Field',
    props: { label: { type: String, required: true } },
    setup(props, { slots }) {
        return () =>
            h('div', { class: 'min-w-0' }, [
                h('div', { class: 'text-muted-foreground text-[11px] uppercase tracking-wide' }, props.label),
                h('div', { class: 'mt-1 break-words' }, slots.default?.()),
            ]);
    },
});
</script>

<template>
    <div>
        <Head title="Jobs" />

        <div class="space-y-4 p-4">
            <header class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Scrape jobs</h1>
                    <p class="text-muted-foreground text-sm">
                        Background work driven by the Playwright worker. Updates live over WebSocket.
                    </p>
                    <p class="text-muted-foreground text-xs max-w-xl">
                        The Rows column shows <strong>received</strong> (rows the scraper POSTed after in-batch dedup) and
                        <strong>inserted</strong> (rows that survived the Postgres unique index). Running jobs update on each
                        <code class="text-xs">POST …/batch</code>.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                        :disabled="(statusCounts.failed ?? 0) === 0"
                        :title="
                            (statusCounts.failed ?? 0) === 0
                                ? 'No failed jobs to remove'
                                : `Remove ${statusCounts.failed} failed job${(statusCounts.failed ?? 0) === 1 ? '' : 's'}`
                        "
                        @click="purgeFailedOpen = true"
                    >
                        <Trash2 class="mr-1 size-4" /> Purge failed jobs
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                        :disabled="purgeableCount === 0"
                        :title="
                            purgeableCount === 0
                                ? 'No completed, failed, or cancelled jobs to purge'
                                : `Purge ${purgeableCount} old job${purgeableCount === 1 ? '' : 's'}`
                        "
                        @click="purgeOpen = true"
                    >
                        <Trash2 class="mr-1 size-4" /> Purge old jobs
                    </Button>
                    <Button variant="outline" size="sm" @click="refresh">
                        <RefreshCw class="mr-1 size-4" /> Refresh
                    </Button>
                </div>
            </header>

            <Card>
                <CardContent class="flex flex-wrap items-center gap-3 py-4">
                    <div class="flex flex-wrap items-center gap-1">
                        <Button
                            v-for="chip in filterChips"
                            :key="chip.status"
                            size="sm"
                            :variant="statusFilter === chip.status ? 'default' : 'outline'"
                            @click="
                                statusFilter = chip.status;
                                applyFilters();
                            "
                        >
                            {{ chip.label }}
                            <span class="text-muted-foreground/80 ml-1 text-[11px] tabular-nums">{{ chip.count }}</span>
                        </Button>
                    </div>
                    <div class="ml-auto flex items-center gap-2">
                        <span class="text-muted-foreground text-xs">Subscription</span>
                        <Select v-model="subscriptionFilter" @update:model-value="applyFilters">
                            <SelectTrigger class="w-[260px]">
                                <SelectValue placeholder="All subscriptions" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All subscriptions</SelectItem>
                                <SelectItem v-for="s in subscriptions" :key="s.id" :value="s.id">
                                    {{ s.name || s.id }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="flex-row items-center justify-between gap-4">
                    <div>
                        <CardTitle class="text-base">{{ jobs.total }} jobs</CardTitle>
                        <CardDescription>Showing {{ jobs.from ?? 0 }}–{{ jobs.to ?? 0 }}</CardDescription>
                    </div>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead class="w-16">#</TableHead>
                                <TableHead>Subscription</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Session</TableHead>
                                <TableHead>Attempts</TableHead>
                                <TableHead>Created</TableHead>
                                <TableHead>Duration</TableHead>
                                <TableHead>Rows</TableHead>
                                <TableHead class="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="job in tableJobs"
                                :key="job.id"
                                class="cursor-pointer"
                                @click="focused = job"
                            >
                                <TableCell class="text-muted-foreground tabular-nums">{{ job.id }}</TableCell>
                                <TableCell class="max-w-[280px] truncate font-medium">
                                    {{ job.subscription_name }}
                                </TableCell>
                                <TableCell>
                                    <div class="flex flex-wrap items-center gap-1">
                                        <Badge :variant="STATUS_META[job.status].variant" class="gap-1 capitalize">
                                            <component
                                                :is="STATUS_META[job.status].icon"
                                                :class="['size-3', job.status === 'running' && 'animate-spin']"
                                            />
                                            {{ STATUS_META[job.status].label }}
                                        </Badge>
                                        <Badge
                                            v-if="jobStopReason(job)"
                                            :variant="STOP_REASON_META[jobStopReason(job)!].variant"
                                            class="font-normal"
                                            :title="STOP_REASON_META[jobStopReason(job)!].description"
                                        >
                                            {{ STOP_REASON_META[jobStopReason(job)!].label }}
                                        </Badge>
                                    </div>
                                </TableCell>
                                <TableCell class="text-muted-foreground text-xs">
                                    <template v-if="job.session_email">
                                        {{ job.session_email }}
                                        <span v-if="job.session_env" class="ml-1 uppercase">({{ job.session_env }})</span>
                                    </template>
                                    <template v-else>—</template>
                                </TableCell>
                                <TableCell class="text-muted-foreground tabular-nums">{{ job.attempts }}</TableCell>
                                <TableCell class="text-muted-foreground text-xs" :title="fmt(job.created_at)">
                                    {{ relative(job.created_at) }}
                                </TableCell>
                                <TableCell class="text-muted-foreground tabular-nums text-xs">{{ duration(job) }}</TableCell>
                                <TableCell class="tabular-nums">
                                    <template v-if="jobRowCounts(job).received !== null || jobRowCounts(job).inserted !== null">
                                        <div
                                            class="font-mono text-xs leading-tight"
                                            :title="`${jobRowCounts(job).received ?? 0} received · ${jobRowCounts(job).inserted ?? 0} inserted (${jobRowCounts(job).duplicates} duplicates)`"
                                        >
                                            <div>{{ compactCount(jobRowCounts(job).received ?? 0) }} <span class="text-muted-foreground">received</span></div>
                                            <div class="text-muted-foreground">{{ compactCount(jobRowCounts(job).inserted ?? 0) }} inserted</div>
                                        </div>
                                    </template>
                                    <template v-else>
                                        <span class="text-muted-foreground">—</span>
                                    </template>
                                </TableCell>
                                <TableCell class="text-right" @click.stop>
                                    <div class="flex justify-end gap-1">
                                        <Button
                                            v-if="job.status === 'failed' || job.status === 'cancelled'"
                                            variant="ghost"
                                            size="sm"
                                            title="Retry"
                                            @click="retry(job)"
                                        >
                                            <RotateCcw class="size-4" />
                                        </Button>
                                        <Button
                                            v-if="job.status === 'queued' || job.status === 'running'"
                                            variant="ghost"
                                            size="sm"
                                            title="Cancel"
                                            @click="cancel(job)"
                                        >
                                            <XCircle class="size-4" />
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            title="Delete"
                                            @click="destroy(job)"
                                        >
                                            <Trash2 class="text-destructive size-4" />
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                            <TableRow v-if="jobs.data.length === 0">
                                <TableCell colspan="9" class="text-muted-foreground py-12 text-center">
                                    <Activity class="mx-auto mb-2 size-6 opacity-40" />
                                    No jobs match these filters.
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>

                    <nav v-if="jobs.last_page > 1" class="mt-4 flex flex-wrap items-center gap-1">
                        <template v-for="link in jobs.links" :key="link.label">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                preserve-state
                                preserve-scroll
                                :class="[
                                    'rounded-md border px-2 py-1 text-xs',
                                    link.active
                                        ? 'bg-primary text-primary-foreground border-primary'
                                        : 'hover:bg-accent',
                                ]"
                            >
                                <span v-html="link.label" />
                            </Link>
                            <span
                                v-else
                                class="text-muted-foreground rounded-md border px-2 py-1 text-xs opacity-50"
                                v-html="link.label"
                            />
                        </template>
                    </nav>
                </CardContent>
            </Card>
        </div>

        <Dialog v-model:open="purgeFailedOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Delete all failed jobs?</DialogTitle>
                    <DialogDescription>
                        This cannot be undone. Only jobs in the failed state are removed; queued, running, completed, and
                        cancelled jobs are not affected.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" :disabled="purgingFailed" @click="purgeFailedOpen = false">Cancel</Button>
                    <Button variant="destructive" :disabled="purgingFailed" @click="purgeFailed">
                        <Loader2 v-if="purgingFailed" class="mr-1 size-4 animate-spin" />
                        Delete failed jobs
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="purgeOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Purge old jobs?</DialogTitle>
                    <DialogDescription>
                        This deletes every completed, failed, or cancelled scrape job for your organizations
                        and resets the job ID counter. Queued and running jobs are kept.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" :disabled="purging" @click="purgeOpen = false">Cancel</Button>
                    <Button variant="destructive" :disabled="purging" @click="purge">
                        <Loader2 v-if="purging" class="mr-1 size-4 animate-spin" />
                        Purge
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog :open="!!focused" @update:open="(o) => !o && (focused = null)">
            <DialogContent class="sm:max-w-2xl max-h-[85vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Job #{{ focused?.id }}</DialogTitle>
                    <DialogDescription class="break-words">
                        {{ focused?.subscription_name }}
                    </DialogDescription>
                </DialogHeader>
                <div v-if="focused" class="space-y-4 text-sm min-w-0">
                    <div class="grid grid-cols-2 gap-3">
                        <Field label="Status">
                            <Badge :variant="STATUS_META[focused.status].variant">{{ STATUS_META[focused.status].label }}</Badge>
                        </Field>
                        <Field label="Attempts">{{ focused.attempts }}</Field>
                        <Field label="Created">{{ fmt(focused.created_at) }}</Field>
                        <Field label="Started">{{ fmt(focused.started_at) }}</Field>
                        <Field label="Completed">{{ fmt(focused.completed_at) }}</Field>
                        <Field label="Heartbeat">{{ fmt(focused.last_heartbeat_at) }}</Field>
                    </div>
                    <Field v-if="jobStopReason(focused)" label="Completion reason">
                        <div class="flex items-center gap-2">
                            <Badge :variant="STOP_REASON_META[jobStopReason(focused)!].variant">
                                {{ STOP_REASON_META[jobStopReason(focused)!].label }}
                            </Badge>
                            <span class="text-muted-foreground text-xs">
                                {{ STOP_REASON_META[jobStopReason(focused)!].description }}
                            </span>
                        </div>
                    </Field>
                    <Field v-if="focused.error" label="Error">
                        <pre class="bg-destructive/10 text-destructive max-h-72 overflow-auto rounded p-2 text-xs whitespace-pre-wrap break-all font-mono">{{ focused.error }}</pre>
                    </Field>
                    <Field v-if="focused.params" label="Params">
                        <div
                            v-if="focusedLogWindow"
                            class="mb-2 space-y-1"
                            title="Range of BookingExperts log events this run fetched — not the scrape runtime."
                        >
                            <div class="text-xs">
                                <span class="text-muted-foreground">Log window:</span>
                                <span class="font-mono ml-1">{{ focusedLogWindow.start }}</span>
                                <span class="text-muted-foreground mx-1">→</span>
                                <span class="font-mono">{{ focusedLogWindow.end }}</span>
                                <span class="text-muted-foreground ml-1">({{ focusedLogWindow.duration }})</span>
                            </div>
                            <small class="text-muted-foreground block text-[11px]">
                                Range of BookingExperts log events this run fetched — not the scrape runtime.
                            </small>
                        </div>
                        <pre class="bg-muted max-h-60 overflow-auto rounded p-2 text-xs whitespace-pre-wrap break-all font-mono">{{ JSON.stringify(focused.params, null, 2) }}</pre>
                    </Field>
                    <Field v-if="focused.stats" label="Rows">
                        <div
                            class="bg-muted/60 mb-2 grid grid-cols-[auto,1fr] gap-x-3 gap-y-1 rounded p-2 font-mono text-xs"
                            :title="focused.status === 'running'
                                ? 'Live counters from /api/worker/jobs/:id/batch — update on each POST.'
                                : 'Final counters at job completion.'"
                        >
                            <span class="text-muted-foreground">Retrieved</span>
                            <span class="tabular-nums">
                                {{ jobRowCounts(focused).received ?? 0 }}
                                <span
                                    v-if="focused.status === 'running'"
                                    class="text-muted-foreground ml-2 not-italic"
                                >(live)</span>
                            </span>
                            <span class="text-muted-foreground">Inserted</span>
                            <span class="tabular-nums">{{ jobRowCounts(focused).inserted ?? 0 }}</span>
                            <span class="text-muted-foreground">Duplicates</span>
                            <span class="tabular-nums">{{ jobRowCounts(focused).duplicates }}</span>
                        </div>
                        <small class="text-muted-foreground block mb-2 text-[11px]">
                            Retrieved = rows POSTed to <code class="text-[11px]">…/batch</code> after in-batch dedup.
                            Inserted = rows that survived the <code class="text-[11px]">(page_id, content_hash)</code> unique index.
                            Duplicates = retrieved − inserted (rows the index rejected as already-scraped).
                        </small>
                        <pre class="bg-muted max-h-60 overflow-auto rounded p-2 text-xs whitespace-pre-wrap break-all font-mono">{{ JSON.stringify(focused.stats, null, 2) }}</pre>
                    </Field>
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>

