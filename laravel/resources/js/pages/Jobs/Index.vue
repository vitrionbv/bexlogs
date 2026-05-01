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
import { computed, defineComponent, h, onBeforeUnmount, onMounted, ref } from 'vue';
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
    stats: Record<string, unknown> | null;
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

const purgeableCount = computed(
    () =>
        (props.statusCounts.completed ?? 0) +
        (props.statusCounts.failed ?? 0) +
        (props.statusCounts.cancelled ?? 0),
);

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
    'scrape-job-updated': () => refresh(),
    'log-batch-inserted': () => refresh(),
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
                </div>
                <div class="flex items-center gap-2">
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
                                v-for="job in jobs.data"
                                :key="job.id"
                                class="cursor-pointer"
                                @click="focused = job"
                            >
                                <TableCell class="text-muted-foreground tabular-nums">{{ job.id }}</TableCell>
                                <TableCell class="max-w-[280px] truncate font-medium">
                                    {{ job.subscription_name }}
                                </TableCell>
                                <TableCell>
                                    <Badge :variant="STATUS_META[job.status].variant" class="gap-1 capitalize">
                                        <component
                                            :is="STATUS_META[job.status].icon"
                                            :class="['size-3', job.status === 'running' && 'animate-spin']"
                                        />
                                        {{ STATUS_META[job.status].label }}
                                    </Badge>
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
                                    {{ (job.stats as any)?.rows ?? '—' }}
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
                    <Field v-if="focused.error" label="Error">
                        <pre class="bg-destructive/10 text-destructive max-h-72 overflow-auto rounded p-2 text-xs whitespace-pre-wrap break-all font-mono">{{ focused.error }}</pre>
                    </Field>
                    <Field v-if="focused.params" label="Params">
                        <pre class="bg-muted max-h-60 overflow-auto rounded p-2 text-xs whitespace-pre-wrap break-all font-mono">{{ JSON.stringify(focused.params, null, 2) }}</pre>
                    </Field>
                    <Field v-if="focused.stats" label="Stats">
                        <pre class="bg-muted max-h-60 overflow-auto rounded p-2 text-xs whitespace-pre-wrap break-all font-mono">{{ JSON.stringify(focused.stats, null, 2) }}</pre>
                    </Field>
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>

