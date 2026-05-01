<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ChevronLeft,
    Download,
    Eye,
    Filter,
    RefreshCw,
    Search,
    Trash2,
    Upload,
    X,
} from 'lucide-vue-next';
import { computed, defineComponent, h, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { usePageChannel } from '@/composables/useRealtime';

interface LogRow {
    id: number;
    timestamp: string;
    type: string;
    action: string;
    method: string;
    path: string | null;
    status: string | null;
    parameters: unknown;
    request: unknown;
    response: unknown;
}

interface Paginator<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    next_page_url: string | null;
    prev_page_url: string | null;
}

interface PageMeta {
    id: number;
    organization: { id: string; name: string };
    application: { id: string; name: string };
    subscription: { id: string; name: string };
}

interface Filters {
    startDate?: string;
    endDate?: string;
    q?: string;
    type?: string;
    entity?: string;
    action?: string;
    method?: string;
    status?: string;
    sort: string;
    direction: 'asc' | 'desc';
    jsonFilters?: Array<{ field: string; value: string }>;
}

/**
 * Render an ISO-8601 timestamp in the user's locale. The raw ISO string is
 * preserved on the cell's `title` attribute for hover-precision (so power
 * users can still grab the exact value without re-parsing locale text).
 */
function formatTimestamp(iso: string): string {
    if (!iso) {
return '';
}

    const d = new Date(iso);

    if (Number.isNaN(d.getTime())) {
return iso;
}

    return d.toLocaleString();
}

/**
 * Debounced free-text search trigger. We re-fetch 250ms after the last
 * keystroke (long enough to coalesce typing, short enough to feel live).
 * `applyFilters` is hoisted, so calling it from a function defined above
 * `defineProps` is safe — it's only invoked from a DOM event handler that
 * fires after the component is mounted.
 */
let searchTimer: ReturnType<typeof setTimeout> | null = null;
function onSearchInput(): void {
    if (searchTimer !== null) {
clearTimeout(searchTimer);
}

    searchTimer = setTimeout(() => applyFilters(), 250);
}

const props = defineProps<{
    page: PageMeta;
    logs: Paginator<LogRow>;
    total: number;
    facets: { types: string[]; actions: string[]; entities: string[]; methods: string[]; statuses: string[] };
    filters: Filters;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Logs', href: '/logs' },
            { title: 'Detail', href: '#' },
        ],
    },
});

const local = ref<Filters>({ ...props.filters, jsonFilters: props.filters.jsonFilters ?? [] });

watch(() => props.filters, (next) => {
    local.value = { ...next, jsonFilters: next.jsonFilters ?? [] };
});

const detailLog = ref<LogRow | null>(null);
const detailOpen = ref(false);
function openDetail(row: LogRow): void {
    detailLog.value = row;
    detailOpen.value = true;
}

function applyFilters(): void {
    router.get(`/logs/${props.page.id}`, normaliseForUrl(local.value) as never, {
        preserveScroll: true,
        preserveState: false,
    });
}

function clearFilters(): void {
    local.value = { sort: 'timestamp', direction: 'desc', jsonFilters: [] };
    applyFilters();
}

function addJsonFilter(): void {
    (local.value.jsonFilters ??= []).push({ field: '', value: '' });
}

function removeJsonFilter(idx: number): void {
    local.value.jsonFilters?.splice(idx, 1);
}

function normaliseForUrl(f: Filters): Record<string, unknown> {
    const out: Record<string, unknown> = {};

    if (f.startDate) {
out.startDate = f.startDate;
}

    if (f.endDate) {
out.endDate = f.endDate;
}

    if (f.q) {
out.q = f.q;
}

    if (f.type) {
out.type = f.type;
}

    if (f.entity) {
out.entity = f.entity;
}

    if (f.action) {
out.action = f.action;
}

    if (f.method) {
out.method = f.method;
}

    if (f.status) {
out.status = f.status;
}

    if (f.sort) {
out.sort = f.sort;
}

    if (f.direction) {
out.direction = f.direction;
}

    const jsonFilters = (f.jsonFilters ?? []).filter((j) => j.field && j.value);

    if (jsonFilters.length) {
out.jsonFilters = jsonFilters;
}

    return out;
}

function methodBadgeVariant(method: string): 'default' | 'secondary' | 'outline' | 'destructive' {
    const m = (method || '').toUpperCase();

    if (m === 'DELETE') {
return 'destructive';
}

    if (m === 'POST' || m === 'PUT' || m === 'PATCH') {
return 'default';
}

    return 'secondary';
}

function statusVariant(status: string | null): 'default' | 'secondary' | 'destructive' | 'outline' {
    const n = parseInt(status ?? '', 10);

    if (Number.isNaN(n)) {
return 'outline';
}

    if (n >= 500) {
return 'destructive';
}

    if (n >= 400) {
return 'destructive';
}

    if (n >= 300) {
return 'secondary';
}

    return 'default';
}

function csrfToken(): string {
    return document.head.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

function deleteAllLogs(): void {
    if (!confirm(`Delete all ${props.total} log entries on this page? This cannot be undone.`)) {
return;
}

    router.delete(`/logs/${props.page.id}/messages`, {
        preserveScroll: true,
        headers: { 'X-CSRF-TOKEN': csrfToken() },
    });
}

function refresh(): void {
    router.reload({ only: ['logs', 'total', 'facets'] });
}

const liveStreamEnabled = ref(true);
const newSinceMount = ref(0);

usePageChannel(props.page.id, {
    'log-batch-inserted': (payload) => {
        const inserted = Number(payload.inserted ?? 0);

        if (!Number.isFinite(inserted) || inserted <= 0) {
return;
}

        if (!liveStreamEnabled.value) {
            newSinceMount.value += inserted;

            return;
        }

        // Only auto-refresh if the user is on page 1 with no scroll, otherwise
        // batches would yank them out of context. They can hit "Refresh" to pull.
        if (props.logs.current_page === 1 && window.scrollY < 200) {
            refresh();
            toast.success(`+${inserted} new entr${inserted === 1 ? 'y' : 'ies'}`);
        } else {
            newSinceMount.value += inserted;
        }
    },
});

onMounted(() => {
    newSinceMount.value = 0;
});

function showLatest(): void {
    newSinceMount.value = 0;

    if (props.logs.current_page !== 1) {
        router.get(`/logs/${props.page.id}`, normaliseForUrl(local.value) as never, {
            preserveScroll: false,
            preserveState: false,
        });
    } else {
        window.scrollTo({ top: 0, behavior: 'smooth' });
        refresh();
    }
}

const fileInput = ref<HTMLInputElement | null>(null);

function triggerImport(): void {
    fileInput.value?.click();
}

function handleImport(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (!file) {
return;
}

    const fd = new FormData();
    fd.append('file', file);
    router.post(`/logs/${props.page.id}/import`, fd, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => router.reload({ only: ['logs', 'total', 'facets'] }),
    });
    (event.target as HTMLInputElement).value = '';
}

const start = computed(() => (props.logs.current_page - 1) * props.logs.per_page + 1);
const end = computed(() => Math.min(start.value + props.logs.data.length - 1, props.total));

const FacetSelect = defineComponent({
    name: 'FacetSelect',
    props: {
        modelValue: { type: String, default: '' },
        options: { type: Array as () => string[], required: true },
        placeholder: { type: String, default: 'Any' },
        label: { type: String, default: '' },
    },
    emits: ['update:modelValue'],
    setup(props, { emit }) {
        return () =>
            h('div', { class: 'flex flex-col gap-1' }, [
                props.label
                    ? h(
                          'label',
                          { class: 'text-muted-foreground text-xs uppercase' },
                          props.label,
                      )
                    : null,
                h(
                    'select',
                    {
                        class:
                            'border-input bg-background h-9 min-w-32 rounded-md border px-2 text-sm',
                        value: props.modelValue,
                        onChange: (e: Event) =>
                            emit('update:modelValue', (e.target as HTMLSelectElement).value),
                    },
                    [
                        h('option', { value: '' }, props.placeholder),
                        ...props.options.map((o) => h('option', { value: o }, o)),
                    ],
                ),
            ]);
    },
});

const DetailSection = defineComponent({
    name: 'DetailSection',
    props: {
        title: { type: String, required: true },
        payload: { type: null as any, default: null },
    },
    setup(props) {
        const text = (): string => {
            if (props.payload === null || props.payload === undefined) {
                return '';
            }

            if (typeof props.payload === 'string') {
                return props.payload;
            }

            try {
                return JSON.stringify(props.payload, null, 2);
            } catch {
                return String(props.payload);
            }
        };

        return () => {
            const body = text();

            if (!body) {
                return null;
            }

            return h('section', { class: 'border-border rounded-md border' }, [
                h(
                    'header',
                    {
                        class:
                            'border-border bg-muted/50 flex items-center justify-between rounded-t-md border-b px-3 py-2',
                    },
                    [
                        h(
                            'h3',
                            { class: 'text-xs font-semibold tracking-wide uppercase' },
                            props.title,
                        ),
                        h(
                            'button',
                            {
                                class:
                                    'text-muted-foreground hover:text-foreground text-xs',
                                onClick: () => navigator.clipboard.writeText(body),
                            },
                            'Copy',
                        ),
                    ],
                ),
                h(
                    'pre',
                    {
                        class:
                            'bg-muted/20 max-h-[40vh] overflow-auto rounded-b-md p-3 font-mono text-xs whitespace-pre-wrap break-all',
                    },
                    body,
                ),
            ]);
        };
    },
});
</script>

<template>
    <Head :title="page.subscription.name" />

    <div class="mx-auto flex w-full max-w-[1400px] flex-col gap-3 p-4 md:p-6">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <Button variant="ghost" size="icon" as-child>
                    <Link href="/logs">
                        <ChevronLeft class="size-4" />
                    </Link>
                </Button>
                <div>
                    <h1 class="text-xl font-semibold tracking-tight">
                        {{ page.subscription.name }}
                    </h1>
                    <p class="text-muted-foreground text-xs">
                        {{ page.organization.name }} → {{ page.application.name }} ·
                        {{ total.toLocaleString() }} logs
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <Button
                    v-if="newSinceMount > 0"
                    variant="default"
                    size="sm"
                    class="animate-pulse"
                    @click="showLatest"
                >
                    Show {{ newSinceMount }} new entr{{ newSinceMount === 1 ? 'y' : 'ies' }}
                </Button>
                <Button variant="outline" size="sm" @click="refresh">
                    <RefreshCw class="size-4" /> Refresh
                </Button>
                <Button variant="outline" size="sm" as-child>
                    <a :href="`/logs/${page.id}/export`">
                        <Download class="size-4" /> Export
                    </a>
                </Button>
                <Button variant="outline" size="sm" @click="triggerImport">
                    <Upload class="size-4" /> Import
                </Button>
                <input
                    ref="fileInput"
                    type="file"
                    accept=".xlsx,.xls"
                    class="hidden"
                    @change="handleImport"
                />
                <Button variant="ghost" size="sm" class="text-destructive" @click="deleteAllLogs">
                    <Trash2 class="size-4" /> Delete all
                </Button>
            </div>
        </header>

        <!-- Filters bar -->
        <Card>
            <CardContent class="flex flex-col gap-3 p-3">
                <!-- Top row: free-text search across action / path / parameters / request / response -->
                <div class="flex items-center gap-2">
                    <Search class="text-muted-foreground size-4 shrink-0" />
                    <Input
                        v-model="local.q"
                        placeholder="Search action, path, payload… (e.g. an entity id)"
                        class="h-9 flex-1"
                        @input="onSearchInput"
                    />
                </div>

                <!-- Bottom row: faceted filters + apply/clear -->
                <div class="flex flex-wrap items-end gap-2">
                <div class="flex flex-col gap-1">
                    <label class="text-muted-foreground text-xs uppercase">From</label>
                    <Input v-model="local.startDate" type="datetime-local" class="h-9 w-44" />
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-muted-foreground text-xs uppercase">To</label>
                    <Input v-model="local.endDate" type="datetime-local" class="h-9 w-44" />
                </div>
                <FacetSelect v-model="local.type" :options="facets.types" placeholder="Any type" label="Type" />
                <FacetSelect v-model="local.entity" :options="facets.entities" placeholder="Any entity" label="Entity" />
                <FacetSelect v-model="local.method" :options="facets.methods" placeholder="Any method" label="Method" />
                <FacetSelect v-model="local.status" :options="facets.statuses" placeholder="Any status" label="Status" />

                <Popover>
                    <PopoverTrigger as-child>
                        <Button variant="outline" size="sm" class="h-9">
                            <Filter class="size-4" /> JSON filters
                            <Badge v-if="local.jsonFilters?.length" variant="secondary" class="ml-1">
                                {{ local.jsonFilters.length }}
                            </Badge>
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent class="w-[420px]">
                        <div class="flex flex-col gap-3">
                            <p class="text-muted-foreground text-xs">
                                Filter on a JSON key in any of parameters / request / response. Example: field=<code>email</code> value=<code>foo@bar</code>.
                            </p>
                            <div
                                v-for="(jf, idx) in local.jsonFilters"
                                :key="idx"
                                class="grid grid-cols-[1fr_1fr_auto] items-center gap-2"
                            >
                                <Input v-model="jf.field" placeholder="field" />
                                <Input v-model="jf.value" placeholder="value" />
                                <Button variant="ghost" size="icon" @click="removeJsonFilter(idx)">
                                    <X class="size-4" />
                                </Button>
                            </div>
                            <Button size="sm" variant="outline" @click="addJsonFilter">+ add filter</Button>
                        </div>
                    </PopoverContent>
                </Popover>

                <div class="ml-auto flex items-end gap-2">
                    <Button variant="outline" @click="clearFilters">Clear</Button>
                    <Button @click="applyFilters">
                        <Search class="size-4" /> Apply
                    </Button>
                </div>
                </div>
            </CardContent>
        </Card>

        <!-- Results table -->
        <Card>
            <CardContent class="p-0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead class="w-[180px]">Timestamp</TableHead>
                            <TableHead class="w-[110px]">Type</TableHead>
                            <TableHead>Entity + Action</TableHead>
                            <TableHead>Method + Path</TableHead>
                            <TableHead class="w-[80px] text-right">Status</TableHead>
                            <TableHead class="w-[60px]"></TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="row in logs.data"
                            :key="row.id"
                            class="cursor-pointer"
                            @click="openDetail(row)"
                        >
                            <TableCell
                                class="text-muted-foreground whitespace-nowrap text-xs"
                                :title="row.timestamp"
                            >
                                {{ formatTimestamp(row.timestamp) }}
                            </TableCell>
                            <TableCell>
                                <Badge variant="outline">{{ row.type }}</Badge>
                            </TableCell>
                            <TableCell class="max-w-[400px] truncate">
                                {{ row.action }}
                            </TableCell>
                            <TableCell class="font-mono text-xs">
                                <Badge :variant="methodBadgeVariant(row.method)" class="font-mono mr-2">
                                    {{ row.method }}
                                </Badge>
                                <span v-if="row.path" class="text-muted-foreground break-all">{{ row.path }}</span>
                            </TableCell>
                            <TableCell class="text-right">
                                <Badge v-if="row.status" :variant="statusVariant(row.status)">
                                    {{ row.status }}
                                </Badge>
                                <span v-else class="text-muted-foreground">—</span>
                            </TableCell>
                            <TableCell>
                                <Eye class="size-4" />
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="!logs.data.length">
                            <TableCell colspan="6" class="text-muted-foreground p-8 text-center">
                                No logs match these filters.
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>

        <!-- Pagination -->
        <footer class="flex items-center justify-between text-sm">
            <span class="text-muted-foreground">
                {{ start.toLocaleString() }}–{{ end.toLocaleString() }} of {{ total.toLocaleString() }}
            </span>
            <div class="flex gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="!logs.prev_page_url"
                    as-child
                >
                    <Link v-if="logs.prev_page_url" :href="logs.prev_page_url" preserve-scroll>Previous</Link>
                    <span v-else>Previous</span>
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="!logs.next_page_url"
                    as-child
                >
                    <Link v-if="logs.next_page_url" :href="logs.next_page_url" preserve-scroll>Next</Link>
                    <span v-else>Next</span>
                </Button>
            </div>
        </footer>

        <!-- Detail dialog -->
        <Dialog v-model:open="detailOpen">
            <DialogContent class="sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        {{ detailLog?.action }}
                        <Badge v-if="detailLog?.method" :variant="methodBadgeVariant(detailLog.method)">
                            {{ detailLog.method }}
                        </Badge>
                        <Badge v-if="detailLog?.status" :variant="statusVariant(detailLog.status)">
                            {{ detailLog.status }}
                        </Badge>
                    </DialogTitle>
                    <DialogDescription class="font-mono text-xs">
                        {{ detailLog?.timestamp }} · {{ detailLog?.type }}
                        <span v-if="detailLog?.path" class="text-muted-foreground">· {{ detailLog.path }}</span>
                    </DialogDescription>
                </DialogHeader>
                <div v-if="detailLog" class="grid max-h-[70vh] grid-cols-1 gap-4 overflow-y-auto">
                    <DetailSection title="Parameters" :payload="detailLog.parameters" />
                    <DetailSection title="Request" :payload="detailLog.request" />
                    <DetailSection title="Response" :payload="detailLog.response" />
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>

