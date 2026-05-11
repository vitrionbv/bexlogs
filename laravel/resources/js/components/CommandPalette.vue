<script setup lang="ts">
// Cmd-K command palette (F16). Globally-mounted dialog triggered by
// ⌘K / Ctrl+K from anywhere in the authenticated app. Renders a
// fuzzy-search prompt grouped by surface (subscriptions, scrape jobs,
// saved queries, pages, actions) and pushes to localStorage every
// time the operator clicks a result so the "Recent" group can
// surface the last ten things they touched.
//
// We deliberately do NOT use the shadcn-vue `Command` component (it
// isn't installed in this project and adding `cmdk-vue` for one
// dialog would balloon the bundle). The keyboard nav + filter logic
// here is hand-rolled but small: arrow keys move the highlight, Enter
// activates, Escape closes. The fetch is debounced 150ms client-side
// so a fast typer doesn't pile in-flight requests on the server.

import { router, usePage } from '@inertiajs/vue3';
import {
    Activity,
    Building2,
    Clock,
    FileText,
    LayoutDashboard,
    Plus,
    Search,
} from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

// Tag set for the group ordering. Reordering this array reorders the
// groups in the palette UI.
type ResultKind = 'recent' | 'subscription' | 'scrape_job' | 'saved_query' | 'page' | 'action';

interface PaletteResult {
    id: string;
    kind: ResultKind;
    label: string;
    sublabel?: string;
    href?: string;
    /**
     * Optional click handler for "action" entries that do something
     * other than navigate (e.g. opening a sub-picker). Either `href`
     * OR `onActivate` must be set — if both are present the handler
     * wins.
     */
    onActivate?: () => void;
}

const open = ref(false);
const query = ref('');
const activeIndex = ref(0);
const inputRef = ref<HTMLInputElement | null>(null);
const loading = ref(false);

interface ServerSearchResponse {
    subscriptions?: PaletteResult[];
    scrape_jobs?: PaletteResult[];
    saved_queries?: PaletteResult[];
}

const remote = ref<ServerSearchResponse>({});

// ─── Recently-visited ────────────────────────────────────────────────────────
//
// Stored as a JSON array under one localStorage key; the most recent
// click sits at index 0 and we cap the list at 10. Plain strings →
// minimal serialisation overhead; the entries embed the same shape as
// remote results so the palette can render them with no special-
// casing beyond a different group header.

const RECENTS_KEY = 'bexlogs.cmdk.recents.v1';
const RECENTS_CAP = 10;
const recents = ref<PaletteResult[]>([]);

function loadRecents(): void {
    try {
        const raw = window.localStorage.getItem(RECENTS_KEY);

        if (!raw) {
            recents.value = [];

            return;
        }

        const parsed = JSON.parse(raw) as unknown;

        if (Array.isArray(parsed)) {
            recents.value = (parsed as PaletteResult[])
                .filter((r) => r && typeof r.id === 'string' && typeof r.label === 'string')
                .slice(0, RECENTS_CAP);
        }
    } catch {
        recents.value = [];
    }
}

function pushRecent(result: PaletteResult): void {
    // Don't store synthetic actions or pages — the operator already
    // knows how to reach those from the sidebar. Only "result"-style
    // entries (subscriptions, jobs, saved queries) get the recents
    // treatment.
    if (result.kind === 'action' || result.kind === 'page') {
        return;
    }

    const key = `${result.kind}:${result.id}`;
    const next = [
        // Strip a dedup version of the just-clicked entry up front so
        // re-clicking a recent item moves it to position 0 instead of
        // creating a duplicate.
        { ...result, kind: 'recent' as ResultKind },
        ...recents.value.filter((r) => `${r.kind === 'recent' ? result.kind : r.kind}:${r.id}` !== key),
    ].slice(0, RECENTS_CAP);

    recents.value = next;

    try {
        window.localStorage.setItem(RECENTS_KEY, JSON.stringify(next));
    } catch {
        // Quota errors etc. are non-fatal; the palette still works,
        // it just won't remember across reloads.
    }
}

// ─── Static groups ───────────────────────────────────────────────────────────

const NAV_ITEMS: PaletteResult[] = [
    { id: 'dashboard', kind: 'page', label: 'Dashboard', href: '/dashboard' },
    { id: 'logs', kind: 'page', label: 'Logs', href: '/logs' },
    { id: 'jobs', kind: 'page', label: 'Jobs', href: '/jobs' },
    { id: 'manage', kind: 'page', label: 'Manage', href: '/manage' },
    { id: 'sessions', kind: 'page', label: 'Sessions', href: '/authenticate' },
    { id: 'settings.profile', kind: 'page', label: 'Settings · Profile', href: '/settings/profile' },
    { id: 'settings.security', kind: 'page', label: 'Settings · Security', href: '/settings/security' },
    { id: 'settings.activity', kind: 'page', label: 'Settings · Activity', href: '/settings/activity' },
    { id: 'settings.appearance', kind: 'page', label: 'Settings · Appearance', href: '/settings/appearance' },
];

const ACTION_ITEMS: PaletteResult[] = [
    {
        id: 'action.add_subscription',
        kind: 'action',
        label: 'Add subscription…',
        sublabel: 'opens the Manage dialog',
        onActivate: () => {
            // Bounce to /manage with a hash that the page can read on
            // load to auto-open the dialog. We don't depend on the
            // page's Vue state from here; the page hooks the hash on
            // mount and dispatches its own open trigger.
            router.visit('/manage#add');
        },
    },
];

// ─── Debounced server fetch ──────────────────────────────────────────────────

let fetchAbort: AbortController | null = null;
let fetchDebounce: ReturnType<typeof setTimeout> | null = null;

async function runSearch(text: string): Promise<void> {
    if (fetchAbort) {
        fetchAbort.abort();
    }

    if (!text) {
        remote.value = {};
        loading.value = false;

        return;
    }

    fetchAbort = new AbortController();
    loading.value = true;

    try {
        const res = await fetch(`/api/search?q=${encodeURIComponent(text)}`, {
            headers: { Accept: 'application/json' },
            signal: fetchAbort.signal,
        });

        if (!res.ok) {
            remote.value = {};

            return;
        }

        remote.value = (await res.json()) as ServerSearchResponse;
    } catch (err) {
        if ((err as { name?: string }).name !== 'AbortError') {
            remote.value = {};
        }
    } finally {
        loading.value = false;
    }
}

watch(query, (next) => {
    if (fetchDebounce) {
        clearTimeout(fetchDebounce);
    }

    fetchDebounce = setTimeout(() => runSearch(next.trim()), 150);
});

// ─── Group composition ───────────────────────────────────────────────────────
//
// The merged result list drives both the keyboard nav (one
// activeIndex into the flat array) and the visual grouping (each item
// knows its `kind`). When the query is empty we show recents +
// actions + nav; once the operator types, the remote groups take
// over and the static groups filter by substring.

const trimmedQuery = computed(() => query.value.trim().toLowerCase());

function staticFilter(item: PaletteResult): boolean {
    if (!trimmedQuery.value) {
        return true;
    }

    return item.label.toLowerCase().includes(trimmedQuery.value)
        || (item.sublabel?.toLowerCase().includes(trimmedQuery.value) ?? false);
}

const groupedResults = computed<{ kind: ResultKind; title: string; items: PaletteResult[] }[]>(() => {
    const groups: { kind: ResultKind; title: string; items: PaletteResult[] }[] = [];

    // Recents only render when the query is empty — once the operator
    // is typing, the live search supersedes the cached list.
    if (!trimmedQuery.value && recents.value.length > 0) {
        groups.push({ kind: 'recent', title: 'Recent', items: recents.value });
    }

    const subs = (remote.value.subscriptions ?? []).filter(staticFilter);

    if (subs.length > 0) {
        groups.push({ kind: 'subscription', title: 'Subscriptions', items: subs });
    }

    const jobs = (remote.value.scrape_jobs ?? []).filter(staticFilter);

    if (jobs.length > 0) {
        groups.push({ kind: 'scrape_job', title: 'Scrape jobs', items: jobs });
    }

    const sq = (remote.value.saved_queries ?? []).filter(staticFilter);

    if (sq.length > 0) {
        groups.push({ kind: 'saved_query', title: 'Saved queries', items: sq });
    }

    const pages = NAV_ITEMS.filter(staticFilter);

    if (pages.length > 0) {
        groups.push({ kind: 'page', title: 'Pages', items: pages });
    }

    const actions = ACTION_ITEMS.filter(staticFilter);

    if (actions.length > 0) {
        groups.push({ kind: 'action', title: 'Actions', items: actions });
    }

    return groups;
});

const flatResults = computed<PaletteResult[]>(() =>
    groupedResults.value.flatMap((g) => g.items),
);

// Keep activeIndex within bounds whenever the result list churns.
watch(flatResults, (next) => {
    if (activeIndex.value >= next.length) {
        activeIndex.value = Math.max(0, next.length - 1);
    }
});

// ─── Keyboard handling ───────────────────────────────────────────────────────

function activate(result: PaletteResult): void {
    pushRecent(result);
    open.value = false;
    query.value = '';

    if (result.onActivate) {
        // Defer the side-effect by one tick so the dialog close
        // animation isn't interrupted by an in-flight Inertia visit.
        nextTick(() => result.onActivate?.());

        return;
    }

    if (result.href) {
        router.visit(result.href);
    }
}

function onListKeydown(e: KeyboardEvent): void {
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        const total = flatResults.value.length;

        if (total === 0) {
            return;
        }

        activeIndex.value = (activeIndex.value + 1) % total;
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        const total = flatResults.value.length;

        if (total === 0) {
            return;
        }

        activeIndex.value = (activeIndex.value - 1 + total) % total;
    } else if (e.key === 'Enter') {
        e.preventDefault();
        const target = flatResults.value[activeIndex.value];

        if (target) {
            activate(target);
        }
    } else if (e.key === 'Escape') {
        open.value = false;
    }
}

// Global ⌘K / Ctrl+K binding. Listens on window so any focused
// element in the document opens the palette. We also bind to `/` as
// a quick "just type" alternative when the focus is NOT inside an
// input/textarea/contenteditable, matching the de-facto search
// pattern (GitHub, Notion, Linear).
function onGlobalKeydown(e: KeyboardEvent): void {
    const inMod = e.metaKey || e.ctrlKey;

    if (inMod && (e.key === 'k' || e.key === 'K')) {
        e.preventDefault();
        open.value = !open.value;

        return;
    }

    if (
        e.key === '/'
        && !inMod
        && !e.altKey
        && !e.shiftKey
        && !isEditableTarget(e.target)
        && !open.value
    ) {
        e.preventDefault();
        open.value = true;
    }
}

function isEditableTarget(t: EventTarget | null): boolean {
    if (!(t instanceof HTMLElement)) {
        return false;
    }

    const tag = t.tagName;

    return (
        tag === 'INPUT'
        || tag === 'TEXTAREA'
        || tag === 'SELECT'
        || t.isContentEditable
    );
}

// Focus the input the moment the dialog opens and reset state.
watch(open, async (isOpen) => {
    if (isOpen) {
        query.value = '';
        remote.value = {};
        activeIndex.value = 0;
        loadRecents();
        await nextTick();
        inputRef.value?.focus();
    }
});

// Only render the palette for authenticated users. The shared page
// props include `auth.user`; missing user means we're on /login and
// the keybind should stay quiet.
const page = usePage();
const isAuthed = computed<boolean>(
    () => !!(page.props as { auth?: { user?: unknown } }).auth?.user,
);

onMounted(() => {
    window.addEventListener('keydown', onGlobalKeydown);
    loadRecents();
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onGlobalKeydown);
});

// Returns the lucide icon for a result kind. Pure rendering helper.
const ICONS: Record<ResultKind, unknown> = {
    recent: Clock,
    subscription: Building2,
    scrape_job: Activity,
    saved_query: FileText,
    page: LayoutDashboard,
    action: Plus,
};

function iconFor(kind: ResultKind): unknown {
    // Special-case a couple of well-known nav entries so the page
    // group icons don't all look identical.
    return ICONS[kind] ?? Search;
}

// Lookup helper: given a result, what's its index in the flat list?
function indexOf(result: PaletteResult): number {
    return flatResults.value.findIndex(
        (r) => r.kind === result.kind && r.id === result.id,
    );
}
</script>

<template>
    <Dialog v-if="isAuthed" v-model:open="open">
        <DialogContent
            class="!top-[20%] !translate-y-0 !max-w-xl !p-0"
            :show-close-button="false"
            @keydown="onListKeydown"
        >
            <DialogHeader class="sr-only">
                <DialogTitle>Command palette</DialogTitle>
            </DialogHeader>

            <div class="flex items-center border-b px-3">
                <Search class="text-muted-foreground mr-2 size-4 shrink-0" />
                <input
                    ref="inputRef"
                    v-model="query"
                    type="text"
                    placeholder="Search subscriptions, jobs, pages…"
                    aria-label="Command palette search"
                    class="placeholder:text-muted-foreground h-11 w-full bg-transparent text-sm outline-none"
                />
                <span v-if="loading" class="text-muted-foreground text-xs">…</span>
                <kbd class="bg-muted text-muted-foreground ml-2 hidden rounded px-1.5 py-0.5 text-[10px] sm:inline">
                    Esc
                </kbd>
            </div>

            <div class="max-h-[60vh] overflow-y-auto p-1">
                <div
                    v-if="flatResults.length === 0"
                    class="text-muted-foreground p-4 text-center text-sm"
                >
                    <template v-if="query">No results for "{{ query }}".</template>
                    <template v-else>Type to search, or pick a recent item.</template>
                </div>

                <template v-for="group in groupedResults" :key="group.kind">
                    <div class="text-muted-foreground px-2 pt-2 pb-1 text-[10px] font-medium uppercase">
                        {{ group.title }}
                    </div>
                    <ul class="space-y-0.5">
                        <li
                            v-for="item in group.items"
                            :key="`${item.kind}-${item.id}`"
                            :data-active="indexOf(item) === activeIndex"
                            class="hover:bg-muted data-[active=true]:bg-muted flex cursor-pointer items-center gap-2 rounded-sm px-2 py-1.5 text-sm"
                            role="option"
                            :aria-selected="indexOf(item) === activeIndex"
                            @click="activate(item)"
                            @mouseenter="activeIndex = indexOf(item)"
                        >
                            <component :is="iconFor(item.kind)" class="text-muted-foreground size-4 shrink-0" />
                            <span class="flex-1 truncate">{{ item.label }}</span>
                            <span
                                v-if="item.sublabel"
                                class="text-muted-foreground truncate text-xs"
                            >
                                {{ item.sublabel }}
                            </span>
                        </li>
                    </ul>
                </template>
            </div>

            <div class="text-muted-foreground border-t px-3 py-2 text-[10px]">
                <kbd class="bg-muted rounded px-1 py-0.5">↑</kbd>
                <kbd class="bg-muted ml-1 rounded px-1 py-0.5">↓</kbd>
                navigate
                <kbd class="bg-muted ml-2 rounded px-1 py-0.5">↵</kbd>
                open
                <span class="text-muted-foreground/60 ml-2">
                    ⌘K to toggle
                </span>
            </div>
        </DialogContent>
    </Dialog>
</template>
