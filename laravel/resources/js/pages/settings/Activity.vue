<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Head, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Filter, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

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
    layout: { breadcrumbs: [{ title: 'Activity', href: '/settings/activity' }] },
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

    router.get('/settings/activity', payload, {
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
    router.get('/settings/activity', {}, { preserveState: true, preserveScroll: true });
}

const anyFiltersActive = computed(
    () =>
        userValue.value !== ANY_USER
        || subjectTypeValue.value !== ANY_TYPE
        || subjectIdValue.value !== ''
        || fromValue.value !== ''
        || toValue.value !== ''
        || selectedActions.value.size > 0,
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

    return `/settings/activity?${params.toString()}`;
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

function actionBadgeVariant(action: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (action.endsWith('.deleted') || action === 'scrape.denied') {
        return 'destructive';
    }

    if (action.startsWith('scrape.') || action.startsWith('session.')) {
        return 'secondary';
    }

    return 'default';
}

// Stringify payloads compactly. Objects render as pretty-JSON in a
// disclosure; primitives render inline. The page never displays the
// raw payload more than two levels deep — operators rarely need the
// 5th-level fields, and dumping the full structure would visually
// dominate the row.
function payloadSummary(row: AuditRow): string {
    if (!row.payload) {
        return '';
    }

    try {
        return JSON.stringify(row.payload);
    } catch {
        return '';
    }
}
</script>

<template>
    <Head title="Activity" />

    <h1 class="sr-only">Activity log</h1>

    <div class="flex flex-col space-y-6">
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
            class="border-border bg-card flex flex-col gap-3 rounded-md border p-3"
            aria-label="Filters"
            data-testid="audit-filters"
        >
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="space-y-1">
                    <Label class="text-muted-foreground text-xs uppercase">User</Label>
                    <Select v-model="userValue">
                        <SelectTrigger class="h-9 w-full">
                            <SelectValue placeholder="Any user" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ANY_USER">Any user</SelectItem>
                            <SelectItem v-for="u in facets.users" :key="u.id" :value="String(u.id)">
                                {{ u.name }}
                                <span class="text-muted-foreground ml-1 text-xs">{{ u.email }}</span>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="space-y-1">
                    <Label class="text-muted-foreground text-xs uppercase">Subject type</Label>
                    <Select v-model="subjectTypeValue">
                        <SelectTrigger class="h-9 w-full">
                            <SelectValue placeholder="Any subject" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ANY_TYPE">Any subject</SelectItem>
                            <SelectItem v-for="t in facets.subject_types" :key="t" :value="t">{{ t }}</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="space-y-1">
                    <Label class="text-muted-foreground text-xs uppercase">Subject ID</Label>
                    <Input
                        v-model="subjectIdValue"
                        placeholder="e.g. 12345"
                        :disabled="subjectTypeValue === ANY_TYPE"
                    />
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div class="space-y-1">
                        <Label class="text-muted-foreground text-xs uppercase">From</Label>
                        <Input v-model="fromValue" type="date" />
                    </div>
                    <div class="space-y-1">
                        <Label class="text-muted-foreground text-xs uppercase">To</Label>
                        <Input v-model="toValue" type="date" />
                    </div>
                </div>
            </div>

            <div class="space-y-1">
                <Label class="text-muted-foreground text-xs uppercase">Actions</Label>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="action in facets.actions"
                        :key="action"
                        type="button"
                        class="cursor-pointer rounded-full border px-2 py-0.5 text-xs transition-colors"
                        :class="
                            selectedActions.has(action)
                                ? 'bg-primary text-primary-foreground border-primary'
                                : 'bg-background text-muted-foreground border-border hover:text-foreground'
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
            Audit rows. Empty state distinguishes between "nothing has
            ever been recorded" (fresh install) and "your filter is
            too narrow" (rare, but happens) — same idiom the Manage
            page uses for its empty filter state.
        -->
        <section v-if="logs.data.length === 0" class="text-muted-foreground rounded-md border p-6 text-sm">
            <template v-if="anyFiltersActive">
                No activity matches the current filters.
                <button class="underline underline-offset-2" @click="clearFilters">Clear them</button>
                to see everything.
            </template>
            <template v-else>
                No activity recorded yet. Take an action somewhere — adding a subscription, toggling auto-scrape, etc. — and it'll show up here.
            </template>
        </section>

        <section v-else class="space-y-2">
            <div
                v-for="row in logs.data"
                :key="row.id"
                class="border-border bg-card rounded-md border p-3 text-sm"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="flex flex-col">
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge :variant="actionBadgeVariant(row.action)">{{ actionLabel(row.action) }}</Badge>
                            <span v-if="row.user" class="text-foreground font-medium">{{ row.user.name }}</span>
                            <span v-else class="text-muted-foreground italic">system</span>
                            <span v-if="row.subject_label" class="text-muted-foreground">
                                on <span class="text-foreground">{{ row.subject_label }}</span>
                            </span>
                            <span v-else-if="row.subject_id" class="text-muted-foreground">
                                on <code class="font-mono text-xs">{{ row.subject_id }}</code>
                            </span>
                        </div>
                        <div class="text-muted-foreground mt-1 text-xs">
                            {{ formatTimestamp(row.created_at) }}
                            <span v-if="row.ip_address"> · {{ row.ip_address }}</span>
                        </div>
                    </div>
                </div>
                <details v-if="row.payload" class="mt-2">
                    <summary class="text-muted-foreground cursor-pointer text-xs hover:underline">
                        Payload
                    </summary>
                    <pre
                        class="bg-muted text-muted-foreground mt-1 overflow-x-auto rounded-md p-2 text-[11px]"
                    >{{ payloadSummary(row) }}</pre>
                </details>
            </div>
        </section>

        <!--
            Pagination footer. We render only prev/next + summary
            because the action list is append-only and operators
            scroll-back rather than jump-to-page. Disabled state is
            driven by the paginator meta so the buttons can't push
            users off the ends of the data.
        -->
        <footer class="text-muted-foreground flex items-center justify-between text-xs">
            <span v-if="logs.meta.total > 0">
                Showing {{ logs.meta.from ?? 0 }}–{{ logs.meta.to ?? 0 }} of {{ logs.meta.total }}
            </span>
            <span v-else>0 rows</span>

            <div class="flex items-center gap-1" v-if="logs.meta.last_page > 1">
                <Button
                    variant="ghost"
                    size="sm"
                    :disabled="logs.meta.current_page <= 1"
                    @click="gotoPage(logs.meta.current_page - 1)"
                >
                    <ChevronLeft class="size-4" /> Prev
                </Button>
                <span class="text-muted-foreground text-xs">
                    Page {{ logs.meta.current_page }} / {{ logs.meta.last_page }}
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
        </footer>
    </div>
</template>
