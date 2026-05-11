<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Head, router } from '@inertiajs/vue3';
import {
    ChevronLeft,
    ChevronRight,
    Code2,
    Filter,
    X,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

interface ActorRef {
    id: number;
    name: string;
    email: string;
}

interface AuditRow {
    id: number;
    user: ActorRef | null;
    action: string;
    subject_type: string | null;
    subject_id: string | null;
    subject_label: string | null;
    payload: Record<string, unknown> | null;
    ip_address: string | null;
    user_agent: string | null;
    created_at: string | null;
}

interface PaginatorMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

interface Filters {
    user_id: number | null;
    actions: string[];
    subject_type: string | null;
    subject_id: string | null;
    from: string | null;
    to: string | null;
}

interface Facets {
    users: ActorRef[];
    actions: string[];
    subject_types: string[];
}

const props = defineProps<{
    logs: { data: AuditRow[]; meta: PaginatorMeta };
    filters: Filters;
    facets: Facets;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Activity', href: '/admin/activity' }],
    },
});

// ─── Filter state ────────────────────────────────────────────────────────────
//
// All filter values are kept local first; we only push them to the
// server via `router.get` when the user hits "Apply". Auto-submitting
// per-keystroke would thrash the server on a multi-select dropdown
// and the date pickers would 400 mid-typing. The Apply button
// debounces the user's intent into a single Inertia visit that
// regenerates the audit table.
//
// `user_id` rides as either a numeric ID or `null` (sentinel for
// "any"). Selects don't render an empty string value cleanly, so the
// dropdown uses the string `'__all'` as the all-rows option and we
// translate to/from null at the IO boundary.

const ANY_USER = '__all';
const ANY_TYPE = '__all';

const userValue = ref<string>(
    props.filters.user_id ? String(props.filters.user_id) : ANY_USER,
);
const subjectTypeValue = ref<string>(props.filters.subject_type ?? ANY_TYPE);
const subjectIdValue = ref<string>(props.filters.subject_id ?? '');
const fromValue = ref<string>(props.filters.from ?? '');
const toValue = ref<string>(props.filters.to ?? '');

// Track action filter as a Set for cheap toggling in the chip UI.
const selectedActions = ref<Set<string>>(new Set(props.filters.actions ?? []));

function toggleAction(action: string): void {
    const next = new Set(selectedActions.value);

    if (next.has(action)) {
        next.delete(action);
    } else {
        next.add(action);
    }

    selectedActions.value = next;
}

function applyFilters(): void {
    // Inertia's `router.get` typings reject `unknown`-valued payloads
    // — widen to FormDataConvertible (the union accepted by the
    // RequestPayload type) so the call site type-checks while still
    // covering the string|string[]|null shapes we put in here.
    const payload: Record<string, FormDataConvertible> = {};

    if (userValue.value !== ANY_USER) {
        payload.user_id = userValue.value;
    }

    if (subjectTypeValue.value !== ANY_TYPE) {
        payload.subject_type = subjectTypeValue.value;

        if (subjectIdValue.value.trim()) {
            payload.subject_id = subjectIdValue.value.trim();
        }
    }

    if (selectedActions.value.size > 0) {
        payload.actions = Array.from(selectedActions.value);
    }

    if (fromValue.value) {
        payload.from = fromValue.value;
    }

    if (toValue.value) {
        payload.to = toValue.value;
    }

    router.get('/admin/activity', payload, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function clearFilters(): void {
    userValue.value = ANY_USER;
    subjectTypeValue.value = ANY_TYPE;
    subjectIdValue.value = '';
    fromValue.value = '';
    toValue.value = '';
    selectedActions.value = new Set();
    router.get(
        '/admin/activity',
        {},
        { preserveState: true, preserveScroll: true },
    );
}

const anyFiltersActive = computed(
    () =>
        userValue.value !== ANY_USER ||
        subjectTypeValue.value !== ANY_TYPE ||
        subjectIdValue.value !== '' ||
        fromValue.value !== '' ||
        toValue.value !== '' ||
        selectedActions.value.size > 0,
);

// When the subject type changes back to "any", reset the id so the
// hidden input doesn't carry a stale value through to the next
// submit.
watch(subjectTypeValue, (next) => {
    if (next === ANY_TYPE) {
        subjectIdValue.value = '';
    }
});

// ─── Pagination link helpers ─────────────────────────────────────────────────
//
// The Inertia paginator we serve from the controller doesn't include
// `prev/next` URLs (we ship `meta` only — see ActivityController) so
// we rebuild them locally from the current query string + the page
// number. `preserveState`/`preserveScroll` keep the filter form
// values in place across page jumps.

function pageUrl(page: number): string {
    const params = new URLSearchParams(window.location.search);
    params.set('page', String(page));

    return `/admin/activity?${params.toString()}`;
}

function gotoPage(page: number): void {
    router.visit(pageUrl(page), { preserveState: true, preserveScroll: true });
}

// ─── Rendering helpers ───────────────────────────────────────────────────────

function formatTimestamp(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const d = new Date(iso);

    if (Number.isNaN(d.getTime())) {
        return iso;
    }

    return d.toLocaleString();
}

function actionLabel(action: string): string {
    return action.replace(/\./g, ' · ');
}

function actionBadgeVariant(
    action: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (action.endsWith('.deleted') || action === 'scrape.denied') {
        return 'destructive';
    }

    if (action.startsWith('scrape.') || action.startsWith('session.')) {
        return 'secondary';
    }

    return 'default';
}

// Pretty-print payloads for the dialog viewer. Two-space indent +
// newlines so the operator can scan the diff at a glance; the dialog
// itself caps the height so a deeply-nested payload scrolls instead
// of pushing the rest of the modal off-screen.
function payloadPretty(row: AuditRow): string {
    if (!row.payload) {
        return '';
    }

    try {
        return JSON.stringify(row.payload, null, 2);
    } catch {
        return '';
    }
}

// `subject_type` arrives as the morph class FQCN (e.g.
// `App\Models\Subscription`). The audit table column is the same
// string so we can't shorten it server-side without breaking the
// MorphTo relation, but the table only ever shows the leaf class
// name to keep the column narrow.
function shortTypeName(fqcn: string | null): string {
    if (!fqcn) {
        return '';
    }

    const parts = fqcn.split('\\');

    return parts[parts.length - 1] ?? fqcn;
}
</script>

<template>
    <Head title="Activity" />

    <h1 class="sr-only">Activity log</h1>

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-4 p-4 md:p-6">
        <Heading
            variant="small"
            title="Activity log"
            description="Every meaningful state change in the app — who did what, and when."
        />

        <!--
            Filter card. Server-side filtering means the URL is the
            source of truth: deep-linking to a filtered view is fully
            supported. Click "Apply" to push the local form state
            through Inertia, "Clear" to wipe the URL of every filter
            param at once.
        -->
        <section
            class="flex flex-col gap-3 rounded-md border border-border bg-card p-3"
            aria-label="Filters"
            data-testid="audit-filters"
        >
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="space-y-1">
                    <Label class="text-xs text-muted-foreground uppercase"
                        >User</Label
                    >
                    <Select v-model="userValue">
                        <SelectTrigger class="h-9 w-full">
                            <SelectValue placeholder="Any user" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ANY_USER">Any user</SelectItem>
                            <SelectItem
                                v-for="u in facets.users"
                                :key="u.id"
                                :value="String(u.id)"
                            >
                                {{ u.name }}
                                <span
                                    class="ml-1 text-xs text-muted-foreground"
                                    >{{ u.email }}</span
                                >
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="space-y-1">
                    <Label class="text-xs text-muted-foreground uppercase"
                        >Subject type</Label
                    >
                    <Select v-model="subjectTypeValue">
                        <SelectTrigger class="h-9 w-full">
                            <SelectValue placeholder="Any subject" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ANY_TYPE"
                                >Any subject</SelectItem
                            >
                            <SelectItem
                                v-for="t in facets.subject_types"
                                :key="t"
                                :value="t"
                                >{{ t }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>

                <div class="space-y-1">
                    <Label class="text-xs text-muted-foreground uppercase"
                        >Subject ID</Label
                    >
                    <Input
                        v-model="subjectIdValue"
                        placeholder="e.g. 12345"
                        :disabled="subjectTypeValue === ANY_TYPE"
                    />
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div class="space-y-1">
                        <Label class="text-xs text-muted-foreground uppercase"
                            >From</Label
                        >
                        <Input v-model="fromValue" type="date" />
                    </div>
                    <div class="space-y-1">
                        <Label class="text-xs text-muted-foreground uppercase"
                            >To</Label
                        >
                        <Input v-model="toValue" type="date" />
                    </div>
                </div>
            </div>

            <div class="space-y-1">
                <Label class="text-xs text-muted-foreground uppercase"
                    >Actions</Label
                >
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="action in facets.actions"
                        :key="action"
                        type="button"
                        class="cursor-pointer rounded-full border px-2 py-0.5 text-xs transition-colors"
                        :class="
                            selectedActions.has(action)
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border bg-background text-muted-foreground hover:text-foreground'
                        "
                        @click="toggleAction(action)"
                    >
                        {{ actionLabel(action) }}
                    </button>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button
                    v-if="anyFiltersActive"
                    variant="ghost"
                    size="sm"
                    @click="clearFilters"
                >
                    <X class="mr-1 size-4" /> Clear
                </Button>
                <Button size="sm" @click="applyFilters">
                    <Filter class="mr-1 size-4" /> Apply
                </Button>
            </div>
        </section>

        <!--
            Audit table. Each row is a single (timestamp, actor,
            action, subject, IP, payload) tuple — the column set
            preserves every field the legacy card layout surfaced;
            user-agent rides as a tooltip on the IP cell instead of
            its own column because UA strings are bulky and rarely
            scanned.

            Empty state is a single full-width row inside the table
            body so the chrome (header row, card border) stays put
            instead of swapping in a separate "no rows" panel — same
            idiom Admin/Users uses.
        -->
        <Card>
            <CardContent class="p-0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead class="w-44">Time</TableHead>
                            <TableHead class="w-44">Actor</TableHead>
                            <TableHead class="w-44">Action</TableHead>
                            <TableHead>Subject</TableHead>
                            <TableHead class="w-36">IP</TableHead>
                            <TableHead class="w-24 text-right">
                                Payload
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-if="logs.data.length === 0"
                            class="hover:bg-transparent"
                        >
                            <TableCell
                                colspan="6"
                                class="text-muted-foreground py-10 text-center text-sm"
                            >
                                <template v-if="anyFiltersActive">
                                    No activity matches the current filters.
                                    <button
                                        class="underline underline-offset-2"
                                        @click="clearFilters"
                                    >
                                        Clear them
                                    </button>
                                    to see everything.
                                </template>
                                <template v-else>
                                    No activity recorded yet. Take an action
                                    somewhere — adding a subscription, toggling
                                    auto-scrape, etc. — and it'll show up here.
                                </template>
                            </TableCell>
                        </TableRow>

                        <TableRow
                            v-for="row in logs.data"
                            :key="row.id"
                            class="align-top"
                        >
                            <TableCell class="text-sm">
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <span class="text-muted-foreground">
                                            {{ formatTimestamp(row.created_at) }}
                                        </span>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        {{ row.created_at ?? '—' }}
                                    </TooltipContent>
                                </Tooltip>
                            </TableCell>
                            <TableCell>
                                <template v-if="row.user">
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <span class="font-medium">
                                                {{ row.user.name }}
                                            </span>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            {{ row.user.email }}
                                        </TooltipContent>
                                    </Tooltip>
                                </template>
                                <span
                                    v-else
                                    class="text-muted-foreground italic"
                                >
                                    system
                                </span>
                            </TableCell>
                            <TableCell>
                                <Badge
                                    :variant="actionBadgeVariant(row.action)"
                                    class="font-mono text-[11px]"
                                >
                                    {{ actionLabel(row.action) }}
                                </Badge>
                            </TableCell>
                            <TableCell>
                                <div class="flex flex-col gap-0.5">
                                    <span
                                        v-if="row.subject_label"
                                        class="text-foreground font-medium"
                                    >
                                        {{ row.subject_label }}
                                    </span>
                                    <code
                                        v-else-if="row.subject_id"
                                        class="font-mono text-xs"
                                    >
                                        {{ row.subject_id }}
                                    </code>
                                    <span v-else class="text-muted-foreground">
                                        —
                                    </span>
                                    <span
                                        v-if="row.subject_type"
                                        class="text-muted-foreground text-xs"
                                    >
                                        {{ shortTypeName(row.subject_type) }}
                                    </span>
                                </div>
                            </TableCell>
                            <TableCell
                                class="text-muted-foreground font-mono text-xs"
                            >
                                <template v-if="row.ip_address">
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <span>{{ row.ip_address }}</span>
                                        </TooltipTrigger>
                                        <TooltipContent class="max-w-md">
                                            <template v-if="row.user_agent">
                                                {{ row.user_agent }}
                                            </template>
                                            <template v-else>
                                                No user-agent recorded
                                            </template>
                                        </TooltipContent>
                                    </Tooltip>
                                </template>
                                <span v-else>—</span>
                            </TableCell>
                            <TableCell class="text-right">
                                <Dialog v-if="row.payload">
                                    <DialogTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            class="h-8 px-2"
                                        >
                                            <Code2 class="size-4" />
                                            <span class="ml-1">View</span>
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent class="sm:max-w-2xl">
                                        <DialogHeader>
                                            <DialogTitle
                                                class="flex items-center gap-2"
                                            >
                                                <Code2 class="size-4" />
                                                Payload
                                            </DialogTitle>
                                            <DialogDescription>
                                                {{ actionLabel(row.action) }} ·
                                                {{
                                                    formatTimestamp(
                                                        row.created_at,
                                                    )
                                                }}
                                            </DialogDescription>
                                        </DialogHeader>
                                        <pre
                                            class="bg-muted text-foreground max-h-[60vh] overflow-auto rounded-md p-3 text-xs"
                                            >{{ payloadPretty(row) }}</pre>
                                    </DialogContent>
                                </Dialog>
                                <span v-else class="text-muted-foreground">
                                    —
                                </span>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>

            <!--
                Pagination footer lives inside the Card so the
                border-t aligns with the table grid. Render-gated by
                `last_page > 1` (no point in showing a single-page
                paginator), but the row-count summary is always
                present when any rows exist.
            -->
            <div
                v-if="logs.meta.total > 0"
                class="border-border text-muted-foreground flex items-center justify-between border-t p-3 text-xs"
            >
                <span>
                    Showing {{ logs.meta.from ?? 0 }}–{{ logs.meta.to ?? 0 }} of
                    {{ logs.meta.total }}
                </span>

                <div
                    v-if="logs.meta.last_page > 1"
                    class="flex items-center gap-1"
                >
                    <Button
                        variant="ghost"
                        size="sm"
                        :disabled="logs.meta.current_page <= 1"
                        @click="gotoPage(logs.meta.current_page - 1)"
                    >
                        <ChevronLeft class="size-4" /> Prev
                    </Button>
                    <span class="text-muted-foreground text-xs">
                        Page {{ logs.meta.current_page }} /
                        {{ logs.meta.last_page }}
                    </span>
                    <Button
                        variant="ghost"
                        size="sm"
                        :disabled="logs.meta.current_page >= logs.meta.last_page"
                        @click="gotoPage(logs.meta.current_page + 1)"
                    >
                        Next <ChevronRight class="size-4" />
                    </Button>
                </div>
            </div>
        </Card>
    </div>
</template>
