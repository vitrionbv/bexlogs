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
    /**
     * Diagnostic counter from `loadInitialPageWithRetry` (scraper).
     * Mirrors `token_echo_retries` in spirit: non-zero means the initial
     * page came back empty on attempt 1 and we had to re-issue the
     * navigate + extract pipeline N times before real data showed up
     * (transient BE load / Cloudflare challenge / cookie race).
     * `TOKEN_ECHO_MAX_ATTEMPTS - 1` means the helper exhausted and the
     * job legitimately completes as `empty_window` (rendered in the
     * `empty_window` tooltip as "after N retries"). Visible in the
     * Stats dialog as a dedicated line; not surfaced as a badge.
     */
    initial_page_retries?: number;
    /**
     * Per-page detail from the retry helpers. Each entry is the
     * total attempts the helper made on a single page (1 = no retry,
     * >1 = N-1 echo retries). Only pages where the helper actually
     * retried land here.
     *
     *   - `page: 0`    → sentinel for the initial-page helper.
     *   - `page: 1..N` → 1-based load_more page number being LOADED
     *                    (so an entry `{page: 90, attempts: 7}` reads
     *                    as "the helper made 7 attempts to fetch page
     *                    90 before either advancing or exhausting").
     *
     * Updates land via /batch (sender-of-truth, every flush) and once
     * more on /complete to catch the tail-page-exhausted case where
     * the last page never flushed. Used to render the per-page
     * "retried N×" badges in the Stats dialog and the live "echoing"
     * hint on the running job's row.
     */
    echo_attempts_by_page?: { page: number; attempts: number }[];
    /**
     * Oldest / newest BookingExperts event timestamps actually
     * observed in any batch during this scrape job's lifetime —
     * rolled up server-side from the per-batch
     * `batch_oldest_event_at` / `batch_newest_event_at` values the
     * worker sends on each /batch POST.
     *
     * Distinct from `params.start_time` / `params.end_time`, which
     * is the *requested* window the scraper asked BE to fetch. The
     * observed range can be narrower (BE's log doesn't go back as
     * far as we asked) or strictly inside the requested window
     * (typical case — we asked for 30d, the activity in that
     * subscription only spans 12d). Both fields render side-by-side
     * in the detail dialog so an operator can spot a backfill that
     * didn't reach the requested depth.
     *
     * Stored as ISO 8601 strings (Carbon::toIso8601String) on the
     * Laravel side; rendered via the same `fmt(...)` helper as the
     * other timestamp fields.
     */
    oldest_event_at?: string;
    newest_event_at?: string;
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
        description: 'Initial page had zero rows and no next_token to chase after every retry in the initial-page policy exhausted (see initial_page_retries in the stats dialog; at defaults, 100 attempts × 3s ≈ 5 min of sleep). Nothing to scrape in this log window.',
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

// Dynamic badge tooltip: base `description` from STOP_REASON_META plus
// any reason-specific diagnostic suffix. Currently only `empty_window`
// opts in — the initial-page retry policy is the whole point of that
// label now, so the operator should see at a glance whether the "No
// activity" badge was a 1-attempt fast-exit or a full 100-attempt
// grind. (Pre-retry-layer jobs had `initial_page_retries` absent; they
// fall through to the plain description, which still reads correctly.)
function stopReasonTooltip(job: Job): string {
    const reason = jobStopReason(job);

    if (!reason) {
        return '';
    }

    const base = STOP_REASON_META[reason].description;

    if (reason === 'empty_window') {
        const retries = job.stats?.initial_page_retries;

        if (typeof retries === 'number') {
            const suffix = retries === 1 ? '1 retry' : `${retries} retries`;

            return `No activity (after ${suffix}). ${base}`;
        }
    }

    return base;
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

    return makeWindow(start, end);
}

// Same `{start, end, duration}` shape as `formatLogWindow`, but
// keyed off arbitrary ISO 8601 strings (used for the
// `stats.oldest_event_at` / `stats.newest_event_at` "Events seen"
// line in the detail dialog — see template). Returns null on any
// parse failure so the template hides the row entirely instead of
// rendering "Invalid Date → Invalid Date (NaNm)".
function makeWindow(start: string, end: string): { start: string; end: string; duration: string } | null {
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

/**
 * Format the per-page echo-attempts list for the detail dialog's
 * "Pages with echo retries" table. Each row gets a human label
 * (page sentinel `0` → "initial", everything else → "page N") so an
 * operator doesn't have to mentally translate the magic value, plus
 * the raw page number as a stable Vue `:key`.
 *
 * Returns an empty array when the stats object lacks the field
 * (legacy jobs from before the 2026-05-11 feature) or the worker
 * sent an empty list (clean scrape, no retries — the common case).
 * The dialog template's `v-if="… .length > 0"` then hides the
 * entire section, so legacy and clean jobs read identically.
 */
function echoAttemptsRows(job: Job): { page: number; label: string; attempts: number }[] {
    const raw = job.stats?.echo_attempts_by_page;

    if (!Array.isArray(raw)) {
        return [];
    }

    return raw
        .filter(
            (e): e is { page: number; attempts: number } =>
                typeof e?.page === 'number' && typeof e?.attempts === 'number',
        )
        .map((e) => ({
            page: e.page,
            label: e.page === 0 ? 'initial' : `page ${e.page}`,
            attempts: e.attempts,
        }));
}

/**
 * Compact "currently retrying" hint for the running-jobs table row.
 * Returns the latest entry of `echo_attempts_by_page` when present,
 * otherwise null. Used to render a small inline badge like
 * "page 90 · 7×" so the operator can see at a glance that the
 * helper is firing on this job without opening the detail dialog.
 */
function latestEchoAttempt(job: Job): { label: string; attempts: number } | null {
    const rows = echoAttemptsRows(job);

    return rows.length > 0 ? rows[rows.length - 1] : null;
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

// Observed event range (oldest → newest BookingExperts event timestamps
// actually ingested by THIS run, rolled up server-side across batches).
// Renders below `focusedLogWindow` so an operator can compare
// requested-vs-observed at a glance — e.g. requested 30d back, observed
// 7d back means BE's log doesn't go that deep on this subscription.
// Hidden when either field is missing (legacy jobs, or jobs that
// haven't shipped a non-empty /batch yet).
const focusedEventsSeen = computed(() => {
    const oldest = focused.value?.stats?.oldest_event_at;
    const newest = focused.value?.stats?.newest_event_at;

    if (typeof oldest !== 'string' || typeof newest !== 'string') {
        return null;
    }

    return makeWindow(oldest, newest);
});

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
                                            :title="stopReasonTooltip(job)"
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
                                    <!--
                                        Small "echoing" hint when the
                                        retry helper has actually fired
                                        on this job. Shown for both
                                        running and completed jobs; on
                                        running jobs it surfaces "we
                                        spent N attempts on page X" in
                                        real time (the worker re-sends
                                        the full list on every /batch).
                                        Click-through to the detail
                                        dialog (via the parent row's
                                        click handler) shows the full
                                        per-page table.
                                    -->
                                    <div
                                        v-if="latestEchoAttempt(job)"
                                        class="text-amber-700 dark:text-amber-300 mt-0.5 font-mono text-[10px] leading-tight"
                                        :title="`Token-echo retry helper most recently fired on ${latestEchoAttempt(job)!.label} with ${latestEchoAttempt(job)!.attempts} attempts. Open the detail dialog for the full per-page list.`"
                                    >
                                        ↻ {{ latestEchoAttempt(job)!.label }} · {{ latestEchoAttempt(job)!.attempts }}×
                                    </div>
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
                                {{ stopReasonTooltip(focused) }}
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
                            title="Time range the scraper was asked to fetch."
                        >
                            <div class="text-xs">
                                <span class="text-muted-foreground">Requested window:</span>
                                <span class="font-mono ml-1">{{ focusedLogWindow.start }}</span>
                                <span class="text-muted-foreground mx-1">→</span>
                                <span class="font-mono">{{ focusedLogWindow.end }}</span>
                                <span class="text-muted-foreground ml-1">({{ focusedLogWindow.duration }})</span>
                            </div>
                            <small class="text-muted-foreground block text-[11px]">
                                Time range the scraper was asked to fetch.
                            </small>
                        </div>
                        <div
                            v-if="focusedEventsSeen"
                            class="mb-2 space-y-1"
                            title="Oldest and newest BookingExperts event timestamps actually ingested by this run."
                        >
                            <div class="text-xs">
                                <span class="text-muted-foreground">Events seen:</span>
                                <span class="font-mono ml-1">{{ focusedEventsSeen.start }}</span>
                                <span class="text-muted-foreground mx-1">→</span>
                                <span class="font-mono">{{ focusedEventsSeen.end }}</span>
                                <span class="text-muted-foreground ml-1">({{ focusedEventsSeen.duration }})</span>
                            </div>
                            <small class="text-muted-foreground block text-[11px]">
                                Oldest and newest BookingExperts event timestamps actually ingested by this run.
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
                            <template v-if="typeof focused.stats?.initial_page_retries === 'number'">
                                <span
                                    class="text-muted-foreground"
                                    title="Number of extra initial-page attempts the scraper spent re-issuing navigate+extract because the first try came back empty. 0 = fast path; high values mean BE served a transient-empty / challenge page repeatedly."
                                >Initial-page retries</span>
                                <span class="tabular-nums">{{ focused.stats?.initial_page_retries ?? 0 }}</span>
                            </template>
                            <template v-if="typeof focused.stats?.token_echo_retries === 'number'">
                                <span
                                    class="text-muted-foreground"
                                    title="Wasted echo attempts absorbed by loadMoreWithTokenEchoRetry. 0 = helper never fired; high values = BE held at the live tip across most of the scrape (consistent with Caught up (live tip))."
                                >Token-echo retries</span>
                                <span class="tabular-nums">{{ focused.stats?.token_echo_retries ?? 0 }}</span>
                            </template>
                        </div>

                        <!--
                            Per-page echo-attempts table — populated from
                            `stats.echo_attempts_by_page`. Only renders
                            when at least one page retried; clean
                            fast-path scrapes (the common case) stay
                            collapsed since there's nothing operator-
                            actionable to show.

                            Entries are kept in arrival order (the
                            scraper appends as it walks pages). Page
                            sentinel `0` is the initial-page helper —
                            relabel it so the operator doesn't have to
                            mentally decode the magic value.
                        -->
                        <div v-if="echoAttemptsRows(focused).length > 0" class="mb-3">
                            <p class="text-muted-foreground mb-1 text-[11px] uppercase tracking-wide">
                                Pages with echo retries
                            </p>
                            <div
                                class="border-border bg-muted/20 max-h-40 overflow-auto rounded border"
                                data-testid="echo-attempts-list"
                            >
                                <table class="w-full text-xs">
                                    <thead class="bg-muted/40 sticky top-0">
                                        <tr>
                                            <th class="text-muted-foreground px-2 py-1 text-left font-medium">Page</th>
                                            <th class="text-muted-foreground px-2 py-1 text-right font-medium">Attempts</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="row in echoAttemptsRows(focused)"
                                            :key="row.page"
                                            class="border-border/40 border-t"
                                        >
                                            <td class="px-2 py-1 font-mono">{{ row.label }}</td>
                                            <td class="px-2 py-1 text-right tabular-nums">
                                                {{ row.attempts }}<span class="text-muted-foreground">×</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p class="text-muted-foreground mt-1 text-[10px]">
                                Each row is the total attempts the
                                <code class="text-[10px]">loadMoreWithTokenEchoRetry</code>
                                helper made for that page before either advancing or
                                hitting the retry ceiling. Clean fast-path pages
                                (1 attempt) are omitted.
                            </p>
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

