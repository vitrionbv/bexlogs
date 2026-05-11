<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ChevronDown,
    Pause,
    Play,
    Radio,
} from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useUserChannel } from '@/composables/useRealtime';

interface LogRow {
    id: number;
    page_id: number;
    subscription_id: string | null;
    subscription_name: string | null;
    environment: string | null;
    timestamp: string;
    type: string;
    action: string;
    method: string;
    path: string | null;
    status: string | null;
}

interface SubscriptionOption {
    id: string;
    name: string;
    environment: string;
}

const props = defineProps<{
    rows: LogRow[];
    subscriptions: SubscriptionOption[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Live tail', href: '/logs/live' }] },
});

// ─── State ─────────────────────────────────────────────────────────────────
//
// Visible window cap: rolling 1000 rows. Anything older than that ages
// off the bottom of the array on every prepend so the DOM stays cheap
// even on a chatty subscription. The cap is per-page (i.e. across the
// whole stream, not per-subscription) — the user is filtering visually
// via the dropdown, not the data store.
const MAX_VISIBLE_ROWS = 1000;
const COMPACT_TAIL_THRESHOLD = 100;

const visibleRows = ref<LogRow[]>([...props.rows]);

// Pause toggle. When paused, incoming batches accumulate in
// `pausedBuffer` instead of being prepended, so the operator's read
// position doesn't shift while they read. Resuming flushes the buffer
// and trims to MAX_VISIBLE_ROWS.
const paused = ref(false);
const pausedBuffer = ref<LogRow[]>([]);

// Auto-scroll keeps the newest row in view when not paused. Default on;
// any user-initiated scroll up disables it (we re-enable on resume).
const autoScroll = ref(true);
const listEl = ref<HTMLElement | null>(null);

// Filter dropdown — single-pick across "all" / individual sub / env
// macros. Stored as the SELECTED value's `kind:value` form so the
// computed filter knows whether we're filtering by sub id, env name,
// or showing everything.
type FilterValue = 'all' | `sub:${string}` | `env:${string}`;

const filter = ref<FilterValue>('all');

const filterOptions = computed(() => {
    const opts: { value: FilterValue; label: string }[] = [
        { value: 'all', label: 'All subscriptions' },
        { value: 'env:production', label: 'Environment: production' },
        { value: 'env:staging', label: 'Environment: staging' },
    ];

    for (const sub of props.subscriptions) {
        opts.push({
            value: `sub:${sub.id}` as FilterValue,
            label: `${sub.name} (${sub.environment})`,
        });
    }

    return opts;
});

const filteredRows = computed(() => {
    if (filter.value === 'all') {
        return visibleRows.value;
    }

    if (filter.value.startsWith('env:')) {
        const env = filter.value.slice(4);

        return visibleRows.value.filter((r) => r.environment === env);
    }

    if (filter.value.startsWith('sub:')) {
        const subId = filter.value.slice(4);

        return visibleRows.value.filter((r) => r.subscription_id === subId);
    }

    return visibleRows.value;
});

// ─── Live updates ──────────────────────────────────────────────────────────
//
// Subscribe to LogBatchInserted on the user firehose. Each event
// carries a page_id + count; we follow up with /logs/live/since/{page}
// to pull the actual rows. Errors on the since fetch are swallowed —
// the operator just sees the next batch instead.

useUserChannel({
    'log-batch-inserted': async (payload) => {
        const pageId = (payload as { page_id?: number }).page_id;

        if (typeof pageId !== 'number') return;

        // Use the highest id we currently know FOR THIS PAGE as the
        // cursor. If we've never seen this page (e.g. brand-new
        // subscription), since=0 fetches the whole batch — which
        // matches what the worker just inserted, capped at 500 by
        // LiveLogsController::SINCE_LIMIT.
        const cursor = visibleRows.value
            .concat(pausedBuffer.value)
            .filter((r) => r.page_id === pageId)
            .reduce((max, r) => Math.max(max, r.id), 0);

        try {
            const res = await fetch(`/logs/live/since/${pageId}?since=${cursor}`, {
                headers: { Accept: 'application/json' },
            });

            if (!res.ok) return;

            const data = (await res.json()) as { rows: LogRow[] };

            if (data.rows.length === 0) return;

            if (paused.value) {
                pausedBuffer.value = [...data.rows, ...pausedBuffer.value];

                return;
            }

            visibleRows.value = [...data.rows, ...visibleRows.value].slice(0, MAX_VISIBLE_ROWS);

            if (autoScroll.value) {
                await nextTick();
                scrollToTop();
            }
        } catch {
            // Network blip; the next batch will catch us up.
        }
    },
});

function togglePaused(): void {
    paused.value = !paused.value;

    if (!paused.value && pausedBuffer.value.length > 0) {
        // Resume: flush buffered rows to the front, keeping the rolling
        // cap. Auto-scroll comes back on at the same time so the user
        // ends up looking at the freshest row again.
        visibleRows.value = [...pausedBuffer.value, ...visibleRows.value].slice(0, MAX_VISIBLE_ROWS);
        pausedBuffer.value = [];
        autoScroll.value = true;
        nextTick(() => scrollToTop());
    }
}

function toggleAutoScroll(): void {
    autoScroll.value = !autoScroll.value;
    if (autoScroll.value) {
        nextTick(() => scrollToTop());
    }
}

function scrollToTop(): void {
    listEl.value?.scrollTo({ top: 0, behavior: 'smooth' });
}

watch(filter, () => {
    if (autoScroll.value) {
        nextTick(() => scrollToTop());
    }
});

const pausedCount = computed(() => pausedBuffer.value.length);
const totalShowing = computed(() => filteredRows.value.length);

// Quick palette for the small environment badge — production = blue,
// staging = amber. Subscriptions inherit their environment.
function envVariant(env: string | null): 'default' | 'secondary' {
    return env === 'production' ? 'default' : 'secondary';
}

function formatTime(iso: string): string {
    const d = new Date(iso);

    if (Number.isNaN(d.getTime())) return iso;

    return d.toLocaleString();
}
</script>

<template>
    <Head title="Live tail" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-4 p-4 md:p-6">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-semibold tracking-tight">
                    <Radio class="size-5" /> Live tail
                </h1>
                <p class="text-muted-foreground text-sm">
                    Streams the same rows the regular Logs page reads —
                    re-uses the existing LogBatchInserted broadcast.
                    No extra browser, no extra scraping mode.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Select :model-value="filter" @update:model-value="(v) => (filter = v as FilterValue)">
                    <SelectTrigger class="h-9 w-[260px]">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent class="max-h-72">
                        <SelectItem
                            v-for="opt in filterOptions"
                            :key="opt.value"
                            :value="opt.value"
                        >
                            {{ opt.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>

                <Button variant="outline" size="sm" @click="toggleAutoScroll">
                    <ChevronDown class="mr-1 size-4" />
                    Auto-scroll: {{ autoScroll ? 'on' : 'off' }}
                </Button>

                <Button :variant="paused ? 'default' : 'outline'" size="sm" @click="togglePaused">
                    <component :is="paused ? Play : Pause" class="mr-1 size-4" />
                    {{ paused ? `Resume (${pausedCount} buffered)` : 'Pause' }}
                </Button>
            </div>
        </header>

        <Card v-if="!subscriptions.length">
            <CardHeader>
                <CardTitle>No subscriptions yet</CardTitle>
                <CardDescription>
                    Add a subscription on
                    <Link href="/manage" class="underline">Manage</Link>
                    to start streaming logs here.
                </CardDescription>
            </CardHeader>
        </Card>

        <Card v-else>
            <CardHeader class="flex-row items-center justify-between gap-2">
                <div>
                    <CardTitle class="text-base">
                        {{ totalShowing.toLocaleString() }}
                        row<span v-if="totalShowing !== 1">s</span>
                    </CardTitle>
                    <CardDescription>
                        Showing the most recent activity, capped at
                        {{ MAX_VISIBLE_ROWS.toLocaleString() }} rows.
                    </CardDescription>
                </div>
                <Badge v-if="paused" variant="warning" class="text-xs">
                    PAUSED · {{ pausedCount }} buffered
                </Badge>
            </CardHeader>
            <CardContent class="p-0">
                <div
                    ref="listEl"
                    class="relative max-h-[70vh] overflow-y-auto"
                    @scroll.passive="(e) => {
                        const el = e.target as HTMLElement;
                        // Disable auto-scroll if the user scrolls down past the first
                        // ~100 rows; re-engage when they scroll back to the top.
                        if (el.scrollTop > COMPACT_TAIL_THRESHOLD) {
                            autoScroll = false;
                        } else {
                            autoScroll = true;
                        }
                    }"
                >
                    <div v-if="!filteredRows.length" class="text-muted-foreground p-6 text-center text-sm">
                        No log rows in the current view.
                    </div>
                    <ul v-else class="divide-border divide-y font-mono text-xs">
                        <li
                            v-for="row in filteredRows"
                            :key="row.id"
                            class="hover:bg-accent grid grid-cols-[auto_auto_1fr_auto] items-center gap-2 px-3 py-1.5 transition-colors"
                        >
                            <span class="text-muted-foreground tabular-nums">
                                {{ formatTime(row.timestamp) }}
                            </span>
                            <Badge
                                v-if="row.environment"
                                :variant="envVariant(row.environment)"
                                class="px-1 py-0 text-[10px] uppercase"
                            >
                                {{ row.environment.slice(0, 4) }}
                            </Badge>
                            <span v-else class="px-1 py-0 text-[10px]"></span>

                            <span class="flex min-w-0 items-center gap-2 truncate">
                                <span class="bg-background shrink-0 rounded px-1 text-[10px] uppercase">
                                    {{ row.method }}
                                </span>
                                <span class="truncate">
                                    {{ row.action }}
                                </span>
                                <span v-if="row.path" class="text-muted-foreground truncate">
                                    {{ row.path }}
                                </span>
                            </span>

                            <span class="flex shrink-0 items-center gap-2">
                                <span v-if="row.status" class="text-muted-foreground">
                                    {{ row.status }}
                                </span>
                                <Link
                                    v-if="row.subscription_name"
                                    :href="`/logs/${row.page_id}`"
                                    class="text-muted-foreground hover:text-foreground truncate text-[11px] underline-offset-2 hover:underline"
                                >
                                    {{ row.subscription_name }}
                                </Link>
                            </span>
                        </li>
                    </ul>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
