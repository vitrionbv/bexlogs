<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    Loader2,
    Pause,
    RefreshCw,
} from 'lucide-vue-next';
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue';
import {
    SidebarGroup,
    SidebarGroupAction,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useUserChannel } from '@/composables/useRealtime';

type RecentJob = {
    id: number;
    subscription_id: string;
    subscription_name: string;
    status: 'queued' | 'running' | 'completed' | 'failed' | 'cancelled';
    created_at: string;
    completed_at: string | null;
    error: string | null;
    rows: number | null;
    rows_received: number | null;
};

type JobSummary = {
    counts: {
        queued: number;
        running: number;
        completed_24h: number;
        failed: number;
    };
    recent: RecentJob[];
    sessions_active: number;
    updated_at: string;
};

const page = usePage();

const summaryLocal = ref<JobSummary | null>(null);

watch(
    () => (page.props as { jobSummary?: JobSummary | null }).jobSummary,
    (s) => {
        summaryLocal.value = s
            ? (JSON.parse(JSON.stringify(s)) as JobSummary)
            : null;
    },
    { immediate: true, deep: true },
);

const counts = computed(() => summaryLocal.value?.counts ?? { queued: 0, running: 0, completed_24h: 0, failed: 0 });
const recent = computed(() => summaryLocal.value?.recent ?? []);

const STATUS_META = {
    queued: { label: 'queued', variant: 'secondary' as const, icon: Pause },
    running: { label: 'running', variant: 'default' as const, icon: Loader2 },
    completed: { label: 'done', variant: 'success' as const, icon: CheckCircle2 },
    failed: { label: 'failed', variant: 'destructive' as const, icon: AlertTriangle },
    cancelled: { label: 'cancelled', variant: 'outline' as const, icon: Pause },
};

let visibilityHandler: (() => void) | null = null;
let safetyTimer: ReturnType<typeof setInterval> | null = null;

function refresh() {
    router.reload({ only: ['jobSummary'] });
}

function compactSidebar(n: number): string {
    if (n >= 1_000_000) {
        return `${(n / 1_000_000).toFixed(1).replace(/\.0$/, '')}M`;
    }

    if (n >= 1000) {
        return `${(n / 1000).toFixed(1).replace(/\.0$/, '')}k`;
    }

    return String(n);
}

function runningRowsHint(job: RecentJob): string {
    if (job.status !== 'running') {
        return '';
    }

    if (job.rows != null && job.rows_received != null && job.rows_received > job.rows) {
        return `${compactSidebar(job.rows)}/${compactSidebar(job.rows_received)}`;
    }

    if (job.rows != null) {
        return compactSidebar(job.rows);
    }

    return '';
}

useUserChannel({
    'scrape-job-updated': (payload: Record<string, unknown>) => {
        const jobId = payload.job_id;
        const rawStats = payload.stats;
        const status = payload.status as RecentJob['status'] | undefined;

        if (typeof jobId === 'number' && rawStats && typeof rawStats === 'object' && summaryLocal.value) {
            const stats = rawStats as {
                rows?: number;
                rows_inserted?: number;
                rows_received?: number;
            };
            const row = summaryLocal.value.recent.find((j) => j.id === jobId);

            if (row) {
                if (status === 'running' && typeof stats.rows_inserted === 'number') {
                    row.rows = stats.rows_inserted;
                } else if (typeof stats.rows === 'number') {
                    row.rows = stats.rows;
                }

                if (typeof stats.rows_received === 'number') {
                    row.rows_received = stats.rows_received;
                }

                if (status) {
                    row.status = status;
                }

                summaryLocal.value = {
                    ...summaryLocal.value,
                    recent: [...summaryLocal.value.recent],
                };

                return;
            }
        }

        refresh();
    },
    'log-batch-inserted': () => refresh(),
});

onMounted(() => {
    visibilityHandler = () => {
        if (document.visibilityState === 'visible') {
refresh();
}
    };
    document.addEventListener('visibilitychange', visibilityHandler);

    // Safety net: refresh once a minute even if a websocket event was missed.
    safetyTimer = setInterval(() => {
        if (document.visibilityState === 'visible') {
refresh();
}
    }, 60_000);
});

onBeforeUnmount(() => {
    if (visibilityHandler) {
document.removeEventListener('visibilitychange', visibilityHandler);
}

    if (safetyTimer) {
clearInterval(safetyTimer);
}
});

function relative(iso: string): string {
    const d = new Date(iso);
    const diffMs = Date.now() - d.getTime();
    const sec = Math.max(1, Math.round(diffMs / 1000));

    if (sec < 60) {
return `${sec}s`;
}

    const min = Math.round(sec / 60);

    if (min < 60) {
return `${min}m`;
}

    const hr = Math.round(min / 60);

    if (hr < 48) {
return `${hr}h`;
}

    const day = Math.round(hr / 24);

    return `${day}d`;
}
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel class="flex items-center justify-between">
            <Link href="/jobs" class="hover:text-foreground">Jobs</Link>
        </SidebarGroupLabel>
        <SidebarGroupAction title="Refresh now" @click="refresh">
            <RefreshCw class="size-3.5" />
        </SidebarGroupAction>
        <SidebarGroupContent>
            <div class="px-2 pb-2">
                <div class="text-muted-foreground grid grid-cols-4 gap-1 text-[10px] uppercase tracking-wide">
                    <div class="text-center">queue</div>
                    <div class="text-center">run</div>
                    <div class="text-center">24h</div>
                    <div class="text-center">err</div>
                </div>
                <div class="mt-1 grid grid-cols-4 gap-1 text-sm font-semibold">
                    <Link href="/jobs?status=queued" class="bg-muted hover:bg-accent rounded px-1 py-0.5 text-center">{{ counts.queued }}</Link>
                    <Link href="/jobs?status=running" class="bg-muted hover:bg-accent rounded px-1 py-0.5 text-center">{{ counts.running }}</Link>
                    <Link href="/jobs?status=completed" class="bg-muted hover:bg-accent rounded px-1 py-0.5 text-center">{{ counts.completed_24h }}</Link>
                    <Link
                        href="/jobs?status=failed"
                        :class="[
                            'rounded px-1 py-0.5 text-center hover:bg-accent',
                            counts.failed > 0 ? 'bg-destructive/15 text-destructive' : 'bg-muted',
                        ]"
                    >{{ counts.failed }}</Link>
                </div>
            </div>

            <SidebarMenu v-if="recent.length">
                <SidebarMenuItem v-for="job in recent" :key="job.id">
                    <SidebarMenuButton as-child :tooltip="job.subscription_name + ' · ' + job.status">
                        <Link :href="'/jobs?focus=' + job.id" class="flex w-full min-w-0 items-center justify-between gap-1">
                            <div class="flex min-w-0 flex-1 items-center gap-1.5">
                                <component
                                    :is="STATUS_META[job.status].icon"
                                    :class="[
                                        'size-3 shrink-0',
                                        job.status === 'running' && 'animate-spin',
                                        job.status === 'failed' && 'text-destructive',
                                        job.status === 'completed' && 'text-success',
                                    ]"
                                />
                                <span class="min-w-0 flex-1 truncate text-xs">{{ job.subscription_name }}</span>
                            </div>
                            <span class="text-muted-foreground shrink-0 text-[10px] tabular-nums text-right leading-tight">
                                <span v-if="runningRowsHint(job)" class="block">{{ runningRowsHint(job) }}</span>
                                <span class="block">{{ relative(job.completed_at ?? job.created_at) }}</span>
                            </span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <p v-else class="text-muted-foreground px-2 pb-2 text-[11px]">
                No recent jobs.
            </p>
        </SidebarGroupContent>
    </SidebarGroup>
</template>
