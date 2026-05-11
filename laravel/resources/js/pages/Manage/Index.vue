<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    AlertCircle,
    Building2,
    ChevronDown,
    ChevronRight,
    ExternalLink,
    KeyRound,
    Loader2,
    Pause,
    Play,
    Plus,
    RefreshCw,
    Search,
    Settings2,
    Trash2,
    X,
} from 'lucide-vue-next';
import { computed, defineComponent, h, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
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
import { Switch } from '@/components/ui/switch';

interface SubRow {
    id: string;
    name: string;
    environment: 'production' | 'staging';
    auto_scrape: boolean;
    scrape_interval_minutes: number;
    max_pages_per_scrape: number;
    lookback_days_first_scrape: number;
    max_duration_minutes: number;
    max_concurrent_jobs: number;
    job_spacing_minutes: number;
    token_echo_max_attempts: number;
    // Data-lifecycle knobs (G19 + G20). NULL on either column means
    // the feature is off:
    //   - `retention_days = null` → keep hot rows forever.
    //   - `archive_after_days = null` → never move rows to cold storage.
    // The lifecycle_counts payload carries the "would prune ~N rows"
    // and "already archived ~N rows" hints we render under each input.
    retention_days: number | null;
    archive_after_days: number | null;
    lifecycle_counts: {
        hot_rows_older_than_retention: number;
        archived_row_count: number;
    };
    last_scraped_at: string | null;
}
interface AppRow {
    id: string;
    name: string;
    subscriptions: SubRow[];
}
interface OrgRow {
    id: string;
    name: string;
    applications: AppRow[];
}

type SortMode = 'name' | 'id' | 'last_scraped' | 'environment';

interface Totals {
    organizations: number;
    applications: number;
    subscriptions: number;
    auto_scrape_on: number;
}

const props = defineProps<{
    organizations: OrgRow[];
    sessionsActive: number;
    filters: { sort: SortMode; q: string };
    totals: Totals;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Manage', href: '/manage' }] } });

// ─── Sort + search ───────────────────────────────────────────────────────────
//
// Both knobs are URL-driven so an Inertia partial reload (the
// `router.patch` calls below that re-fetch `organizations` after an
// inline edit) keeps the current view stable: the browser stays on
// `/manage?sort=foo&q=bar`, the patch redirects back via `back()`,
// and the response carries the same sorted/filtered organizations
// prop. No client-side reshuffling, no flash of un-sorted content.
//
// Server-side ordering also means the row the user just edited stays
// in its slot — the original "jump" bug — because the ORDER BY clause
// is deterministic across runs (every level has an `id` tiebreaker).

const SORT_OPTIONS: { value: SortMode; label: string; hint: string }[] = [
    { value: 'name', label: 'Name (A–Z)', hint: 'Default. Stable while editing.' },
    { value: 'id', label: 'ID', hint: 'Numeric subscription ID. Never moves on edit.' },
    { value: 'last_scraped', label: 'Last scraped', hint: 'Most recent first; never-scraped last.' },
    { value: 'environment', label: 'Environment', hint: 'Groups production / staging.' },
];

const sortMode = ref<SortMode>(props.filters.sort);
const searchQuery = ref<string>(props.filters.q);

// Debounce search input so typing doesn't fire an Inertia visit per
// keystroke. 200ms is fast enough to feel live but slow enough to
// avoid hammering the server with in-flight requests.
let searchDebounce: ReturnType<typeof setTimeout> | null = null;

function applyFilters(): void {
    router.get(
        '/manage',
        // Drop empty `q` so the URL doesn't carry `?q=` noise when
        // the search box is empty.
        {
            sort: sortMode.value,
            ...(searchQuery.value ? { q: searchQuery.value } : {}),
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            // Only the parts that actually change with the filter.
            // `sessionsActive` is unaffected; leave the prop in place.
            only: ['organizations', 'filters', 'totals'],
        },
    );
}

function onSortChange(next: SortMode): void {
    sortMode.value = next;
    applyFilters();
}

function onSearchInput(value: string): void {
    searchQuery.value = value;

    if (searchDebounce) {
clearTimeout(searchDebounce);
}

    searchDebounce = setTimeout(() => applyFilters(), 200);
}

function clearSearch(): void {
    searchQuery.value = '';

    if (searchDebounce) {
clearTimeout(searchDebounce);
}

    applyFilters();
}

// ─── Per-subscription expand/collapse ────────────────────────────────────────
//
// With many subscriptions the page used to render eight form fields
// per row (auto-scrape, interval, three budget knobs, two concurrency
// knobs, actions). That's a wall of inputs once you have more than a
// handful of subs. Collapse the expensive bottom blocks by default;
// the always-visible header row keeps the common controls (auto
// toggle, interval, Scrape now, Delete) one click away.
//
// State is a Set of subscription IDs that are currently expanded,
// plus an `expandAll` toggle for the bulk path. Local-only — not
// persisted across navigations — so a fresh visit always starts
// compact regardless of where the user left off.

const expandedSubs = ref<Set<string>>(new Set());
const expandAll = ref<boolean>(false);

function isExpanded(subId: string): boolean {
    return expandAll.value || expandedSubs.value.has(subId);
}

function toggleExpanded(subId: string): void {
    const next = new Set(expandedSubs.value);

    if (next.has(subId)) {
        next.delete(subId);
    } else {
        next.add(subId);
    }

    expandedSubs.value = next;
}

function toggleExpandAll(): void {
    expandAll.value = !expandAll.value;

    if (!expandAll.value) {
        // Collapsing "expand all" wipes any per-row expansions too —
        // intent of the bulk button is "reset to compact".
        expandedSubs.value = new Set();
    }
}

// Format a `last_scraped_at` ISO string as a short relative-ish label
// for the header row. We intentionally avoid pulling in date-fns
// here; the four cases below cover the operator's mental model
// ("just now / minutes / hours / days") without locale strings or
// heavy formatters.
function formatLastScraped(iso: string | null): string {
    if (!iso) {
return 'never';
}

    const ms = Date.now() - new Date(iso).getTime();

    if (!Number.isFinite(ms) || ms < 0) {
return 'just now';
}

    const minutes = Math.floor(ms / 60_000);

    if (minutes < 1) {
return 'just now';
}

    if (minutes < 60) {
return `${minutes}m ago`;
}

    const hours = Math.floor(minutes / 60);

    if (hours < 24) {
return `${hours}h ago`;
}

    const days = Math.floor(hours / 24);

    return `${days}d ago`;
}

const hasFilter = computed(() => searchQuery.value.length > 0);
const visibleSubCount = computed(() =>
    props.organizations.reduce(
        (acc, o) => acc + o.applications.reduce((a, app) => a + app.subscriptions.length, 0),
        0,
    ),
);

// ─── Bulk selection (F17) ────────────────────────────────────────────────────
//
// Operators with many subscriptions kept asking for a way to pause /
// resume / re-budget multiple rows in a single click. The selection
// state is a plain Set keyed by subscription id — flat and cheap to
// reason about. The toolbar slides in once at least one row is
// ticked and stays sticky to the top so it's always reachable even
// after scrolling deep into the org list.
//
// "Select all visible" toggles every sub the current filter is
// showing. Subscriptions filtered out by the search box are
// intentionally NOT touched: a hidden row can't be reasoned about by
// the operator at click-time, so we don't include it in bulk
// operations either. That keeps the "I selected too much" risk
// bounded to what the user can actually see.

const selectedIds = ref<Set<string>>(new Set());

const allVisibleSubs = computed<SubRow[]>(() =>
    props.organizations.flatMap((o) => o.applications.flatMap((a) => a.subscriptions)),
);

const selectedCount = computed(() => selectedIds.value.size);

const allVisibleSelected = computed(
    () =>
        allVisibleSubs.value.length > 0
        && allVisibleSubs.value.every((s) => selectedIds.value.has(s.id)),
);

function isSelected(subId: string): boolean {
    return selectedIds.value.has(subId);
}

function toggleSelected(subId: string): void {
    const next = new Set(selectedIds.value);

    if (next.has(subId)) {
        next.delete(subId);
    } else {
        next.add(subId);
    }

    selectedIds.value = next;
}

function toggleSelectAllVisible(): void {
    if (allVisibleSelected.value) {
        // Clear only the *visible* selection so a partially-selected
        // hidden set survives a "deselect all visible" click. In
        // practice nothing's hidden once the user has the filter
        // toolbar's text query cleared, but the property is what
        // makes "select all" feel safe.
        const next = new Set(selectedIds.value);
        allVisibleSubs.value.forEach((s) => next.delete(s.id));
        selectedIds.value = next;
    } else {
        const next = new Set(selectedIds.value);
        allVisibleSubs.value.forEach((s) => next.add(s.id));
        selectedIds.value = next;
    }
}

function clearSelection(): void {
    selectedIds.value = new Set();
}

const selectedSubs = computed<SubRow[]>(() =>
    allVisibleSubs.value.filter((s) => selectedIds.value.has(s.id)),
);

// ─── Bulk operations (F17) ───────────────────────────────────────────────────
//
// Each operation maps to a dedicated server endpoint that mirrors the
// per-sub validation but accepts an `subscription_ids` array. The
// budget modal uses an opt-in checkbox per field so leaving a knob
// untouched means "don't send this column to the server", which is
// the safest default for a sparse-update endpoint.

type BulkBudgetForm = {
    apply: Record<BudgetField | 'auto_scrape' | 'scrape_interval_minutes', boolean>;
    values: {
        auto_scrape: boolean;
        scrape_interval_minutes: number;
        max_pages_per_scrape: number;
        lookback_days_first_scrape: number;
        max_duration_minutes: number;
        max_concurrent_jobs: number;
        job_spacing_minutes: number;
        token_echo_max_attempts: number;
    };
};

function blankBudgetForm(): BulkBudgetForm {
    return {
        apply: {
            auto_scrape: false,
            scrape_interval_minutes: false,
            max_pages_per_scrape: false,
            lookback_days_first_scrape: false,
            max_duration_minutes: false,
            max_concurrent_jobs: false,
            job_spacing_minutes: false,
            token_echo_max_attempts: false,
        },
        values: {
            auto_scrape: true,
            scrape_interval_minutes: 5,
            max_pages_per_scrape: 200,
            lookback_days_first_scrape: 30,
            max_duration_minutes: 30,
            max_concurrent_jobs: 1,
            job_spacing_minutes: 10,
            token_echo_max_attempts: 100,
        },
    };
}

const budgetForm = ref<BulkBudgetForm>(blankBudgetForm());
const budgetDialogOpen = ref(false);
const deleteDialogOpen = ref(false);
const bulkProcessing = ref(false);

function bulkPayloadIds(): string[] {
    return Array.from(selectedIds.value);
}

function bulkPause(): void {
    bulkProcessing.value = true;
    router.patch(
        '/manage/subscriptions/bulk',
        { subscription_ids: bulkPayloadIds(), auto_scrape: false },
        {
            preserveScroll: true,
            onFinish: () => {
                bulkProcessing.value = false;
                clearSelection();
            },
        },
    );
}

function bulkResume(): void {
    bulkProcessing.value = true;
    router.patch(
        '/manage/subscriptions/bulk',
        { subscription_ids: bulkPayloadIds(), auto_scrape: true },
        {
            preserveScroll: true,
            onFinish: () => {
                bulkProcessing.value = false;
                clearSelection();
            },
        },
    );
}

function bulkScrape(): void {
    bulkProcessing.value = true;
    router.post(
        '/manage/subscriptions/bulk/scrape',
        { subscription_ids: bulkPayloadIds() },
        {
            preserveScroll: true,
            onFinish: () => {
                bulkProcessing.value = false;
                // We intentionally KEEP the selection after a bulk
                // scrape so the operator can re-trigger or follow up
                // with a budget edit on the same set without
                // re-ticking everything.
            },
        },
    );
}

function submitBulkBudget(): void {
    // Translate the (apply[], values{}) shape into a sparse server
    // payload — only the columns whose `apply` checkbox is ticked
    // ride along. This matches the controller's "sometimes" validators.
    // The value type widens to FormDataConvertible because Inertia's
    // `router.patch` typings reject `unknown`-valued payloads.
    const payload: Record<string, FormDataConvertible> = {
        subscription_ids: bulkPayloadIds(),
    };

    (Object.keys(budgetForm.value.apply) as (keyof BulkBudgetForm['apply'])[]).forEach((key) => {
        if (budgetForm.value.apply[key]) {
            payload[key] = budgetForm.value.values[key];
        }
    });

    // Spec says "leaving the checkbox off means don't touch this
    // field"; if EVERY checkbox is off the server will flash a no-op
    // status. Guard client-side too so the dialog stays open with a
    // toast nudge instead of vanishing on an empty payload.
    const touched
        = Object.values(budgetForm.value.apply).some(Boolean)
        && Object.keys(payload).length > 1;

    if (!touched) {
        toast.error('Tick at least one "Apply" checkbox to bulk-update.');

        return;
    }

    bulkProcessing.value = true;
    router.patch('/manage/subscriptions/bulk', payload, {
        preserveScroll: true,
        onSuccess: () => {
            budgetDialogOpen.value = false;
            budgetForm.value = blankBudgetForm();
            clearSelection();
        },
        onFinish: () => {
            bulkProcessing.value = false;
        },
    });
}

function submitBulkDelete(): void {
    bulkProcessing.value = true;
    router.delete('/manage/subscriptions/bulk', {
        data: { subscription_ids: bulkPayloadIds() },
        preserveScroll: true,
        onSuccess: () => {
            deleteDialogOpen.value = false;
            clearSelection();
        },
        onFinish: () => {
            bulkProcessing.value = false;
        },
    });
}

const dialogOpen = ref(false);
type TabId = 'browse' | 'url' | 'manual';
const activeTab = ref<TabId>('browse');

type BrowseEnv = 'production' | 'staging';

const newSub = useForm({
    url: '',
    organization_id: '',
    application_id: '',
    subscription_id: '',
    organization_name: '',
    application_name: '',
    subscription_name: '',
    environment: 'production' as BrowseEnv,
});

function submitNew(): void {
    newSub.post('/manage/subscriptions', {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
            newSub.reset();
            resetBrowseSelection();
            toast.success('Subscription saved');
        },
    });
}

function toggleAuto(sub: SubRow): void {
    router.patch(
        `/manage/subscriptions/${sub.id}`,
        { auto_scrape: !sub.auto_scrape },
        { preserveScroll: true, only: ['organizations'] },
    );
}

function updateInterval(sub: SubRow, value: number): void {
    router.patch(
        `/manage/subscriptions/${sub.id}`,
        { scrape_interval_minutes: value },
        { preserveScroll: true, only: ['organizations'] },
    );
}

type BudgetField =
    | 'max_pages_per_scrape'
    | 'lookback_days_first_scrape'
    | 'max_duration_minutes'
    | 'max_concurrent_jobs'
    | 'job_spacing_minutes'
    | 'token_echo_max_attempts';

// Lifecycle fields are tracked separately because they accept NULL
// (an empty input clears the column = "feature off"), while the
// existing budget fields all require a finite integer. Mixing both
// shapes into one BudgetField union would force every callsite to
// branch on null-vs-number.
type LifecycleField = 'retention_days' | 'archive_after_days';

// Per-field bounds that mirror ManageController::updateSubscription's
// validation. Keep the UI's `min`/`max` attributes in sync with these so
// rejected values are caught client-side rather than round-tripping a
// 422.
const BUDGET_BOUNDS: Record<BudgetField, { min: number; max: number }> = {
    max_pages_per_scrape: { min: 1, max: 5000 },
    lookback_days_first_scrape: { min: 1, max: 365 },
    max_duration_minutes: { min: 1, max: 120 },
    max_concurrent_jobs: { min: 1, max: 10 },
    job_spacing_minutes: { min: 1, max: 120 },
    // 1000 ceiling matches the controller validator. Default 100
    // matches the env-wide TOKEN_ECHO_MAX_ATTEMPTS, so untouched rows
    // keep behaving identically.
    token_echo_max_attempts: { min: 1, max: 1000 },
};

// Lifecycle bounds — matched to the controller validator and the
// migration's smallint range. Retention starts at 1 day so an
// operator can aggressively pause a noisy webhook sub; archive
// starts at 7 days so rows never get pushed to cold storage before
// the operator has a chance to inspect them in the hot tier.
const LIFECYCLE_BOUNDS: Record<LifecycleField, { min: number; max: number }> = {
    retention_days: { min: 1, max: 3650 },
    archive_after_days: { min: 7, max: 3650 },
};

function updateBudget(sub: SubRow, field: BudgetField, value: number): void {
    const bounds = BUDGET_BOUNDS[field];

    if (!Number.isFinite(value) || value < bounds.min || value > bounds.max) {
        return;
    }

    router.patch(
        `/manage/subscriptions/${sub.id}`,
        { [field]: value },
        { preserveScroll: true, only: ['organizations'] },
    );
}

// Confirmation prompt state for the "shortening retention shrinks
// the hot table by ~N rows" warning. Stored as a local ref so a
// single dialog instance can serve every row.
const retentionConfirm = ref<{
    sub: SubRow;
    nextValue: number | null;
    impactCount: number;
} | null>(null);

/**
 * Send a lifecycle field update to the backend. Empty/NaN values
 * collapse to NULL (= "feature off"), so the backend column gets
 * cleared. Otherwise the value is bounds-checked client-side.
 *
 * Shortening the retention window is gated behind a confirmation
 * modal — the operator should explicitly acknowledge that the next
 * nightly run will delete a non-trivial number of rows. We use the
 * server-provided `hot_rows_older_than_retention` count from the
 * Manage payload as the impact estimate.
 */
function updateLifecycle(sub: SubRow, field: LifecycleField, raw: string): void {
    const trimmed = raw.trim();
    let nextValue: number | null;

    if (trimmed === '') {
        nextValue = null;
    } else {
        const parsed = Number(trimmed);
        const bounds = LIFECYCLE_BOUNDS[field];
        if (!Number.isFinite(parsed) || parsed < bounds.min || parsed > bounds.max) {
            return;
        }
        nextValue = Math.floor(parsed);
    }

    if (
        field === 'retention_days'
        && nextValue !== null
        && (sub.retention_days === null || nextValue < sub.retention_days)
    ) {
        // Compute a fresh "would prune" count for the operator's
        // chosen window so the modal copy reflects what they typed,
        // not just the server's rendered hint (which always reflects
        // the *current* persisted retention).
        const projection = projectRetentionImpact(sub, nextValue);
        if (projection > 0) {
            retentionConfirm.value = {
                sub,
                nextValue,
                impactCount: projection,
            };
            return;
        }
    }

    persistLifecycleUpdate(sub, field, nextValue);
}

function persistLifecycleUpdate(sub: SubRow, field: LifecycleField, value: number | null): void {
    router.patch(
        `/manage/subscriptions/${sub.id}`,
        { [field]: value },
        { preserveScroll: true, only: ['organizations'] },
    );
}

/**
 * Approximate "if retention drops to N days, how many rows would
 * the next nightly run prune?" using the server-provided impact
 * baseline. The server reports `hot_rows_older_than_retention`
 * computed against the CURRENT retention; when there's no current
 * retention we use it as a proxy for the always-keep volume.
 *
 * For a tighter window we fall back to that baseline — the actual
 * count for an arbitrary cutoff would require a server round-trip,
 * which is more friction than the warning is worth. The number is
 * a hint, not a precise figure.
 */
function projectRetentionImpact(sub: SubRow, _nextDays: number): number {
    return sub.lifecycle_counts.hot_rows_older_than_retention || 0;
}

function confirmRetentionShorten(): void {
    if (!retentionConfirm.value) return;
    const { sub, nextValue } = retentionConfirm.value;
    persistLifecycleUpdate(sub, 'retention_days', nextValue);
    retentionConfirm.value = null;
}

function dismissRetentionShorten(): void {
    retentionConfirm.value = null;
}

function deleteSub(sub: SubRow): void {
    if (!confirm(`Delete subscription "${sub.name}" and all its logs?`)) {
        return;
    }

    router.delete(`/manage/subscriptions/${sub.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Subscription deleted'),
    });
}

function scrapeNow(sub: SubRow): void {
    // Both the success path and the guard-denial paths flash a `toast`
    // session value (`Inertia::flash('toast', ...)`) which the global
    // `initializeFlashToast` handler surfaces. Inertia's `onSuccess`
    // fires for any 2xx/3xx outcome and can't tell success from
    // gated-denial, so emitting a local toast here would double up.
    router.post(
        `/manage/subscriptions/${sub.id}/scrape`,
        {},
        {
            preserveScroll: true,
            onError: (errs) => {
                const first = Object.values(errs)[0];

                if (first) {
                    toast.error(String(first));
                }
            },
        },
    );
}

// ─── Browse tab state ────────────────────────────────────────────────────────
//
// Browse is an app-first cascade: Application → Organization → Subscription.
// We start by listing every application the user can see across all dev-orgs,
// then narrow by customer-org, then pick a subscription. When exactly one
// subscription exists for the (app, org) pair we auto-select it and show a
// muted "Auto-selected: …" line with a "Change" link that reveals the
// dropdown — saves a click for the common 1-org-1-sub case.
//
// Any 401/403 from BookingExperts surfaces as `requires_session_for`,
// which swaps the entire cascade for an inline "Authenticate now" alert
// (the dialog stays open so the user doesn't lose their progress).

interface BrowseApplication {
    id: string;
    name: string;
    organization_id: string;
    organization_name: string;
}

interface BrowseOrganization {
    organization_id: string;
    organization_name: string;
    subscription_count: number;
}

interface BrowseSubscription {
    id: string;
    name: string;
    organization_id: string;
    organization_name: string;
    developer_organization_id: string;
    developer_organization_name: string;
}

const browseEnv = ref<BrowseEnv>('production');
const browseApps = ref<BrowseApplication[]>([]);
const browseOrgs = ref<BrowseOrganization[]>([]);
const browseSubs = ref<BrowseSubscription[]>([]);
const browseLoading = ref<{ apps: boolean; orgs: boolean; subs: boolean }>({
    apps: false,
    orgs: false,
    subs: false,
});
const browseError = ref<string | null>(null);
const browseRequiresSessionFor = ref<BrowseEnv | null>(null);

const selectedAppId = ref<string>('');
const selectedOrgId = ref<string>('');
const selectedSubId = ref<string>('');
// When the (app, org) pair has exactly one subscription, we auto-select
// it. The user can click "Change" to reveal a dropdown and override.
const subDropdownForced = ref(false);

const selectedApp = computed(
    () => browseApps.value.find((a) => a.id === selectedAppId.value) ?? null,
);
const selectedOrg = computed(
    () => browseOrgs.value.find((o) => o.organization_id === selectedOrgId.value) ?? null,
);
const selectedSub = computed(
    () => browseSubs.value.find((s) => s.id === selectedSubId.value) ?? null,
);

const showSubDropdown = computed(
    () => browseSubs.value.length !== 1 || subDropdownForced.value,
);

const browseAuthHref = computed(
    () => `/authenticate?environment=${browseRequiresSessionFor.value ?? browseEnv.value}`,
);

interface BrowseResponse {
    requires_session_for?: BrowseEnv | null;
    message?: string;
    applications?: BrowseApplication[];
    organizations?: BrowseOrganization[];
    subscriptions?: BrowseSubscription[];
}

async function fetchBrowse(url: string): Promise<BrowseResponse | null> {
    try {
        const res = await fetch(url, { headers: { Accept: 'application/json' } });

        if (!res.ok) {
            browseError.value = `Request failed: ${res.status}`;

            return null;
        }

        const data = (await res.json()) as BrowseResponse;

        if (data.requires_session_for) {
            browseRequiresSessionFor.value = data.requires_session_for;
            browseError.value = null;
        } else {
            browseRequiresSessionFor.value = null;
            browseError.value = null;
        }

        return data;
    } catch (err) {
        browseError.value = (err as Error)?.message ?? 'Network error';

        return null;
    }
}

function clearStep2(): void {
    selectedOrgId.value = '';
    browseOrgs.value = [];
    clearStep3();
}

function clearStep3(): void {
    selectedSubId.value = '';
    browseSubs.value = [];
    subDropdownForced.value = false;
}

function resetBrowseSelection(): void {
    selectedAppId.value = '';
    clearStep2();
}

async function loadApps(): Promise<void> {
    browseLoading.value.apps = true;
    resetBrowseSelection();

    try {
        const data = await fetchBrowse(
            `/manage/browse/applications?environment=${browseEnv.value}`,
        );
        browseApps.value = data?.applications ?? [];
    } finally {
        browseLoading.value.apps = false;
    }
}

async function loadOrgs(appId: string): Promise<void> {
    clearStep2();

    if (!appId) {
        return;
    }

    browseLoading.value.orgs = true;

    try {
        const data = await fetchBrowse(
            `/manage/browse/applications/${appId}/organizations?environment=${browseEnv.value}`,
        );
        browseOrgs.value = data?.organizations ?? [];
    } finally {
        browseLoading.value.orgs = false;
    }
}

async function loadSubs(appId: string, orgId: string): Promise<void> {
    clearStep3();

    if (!appId || !orgId) {
        return;
    }

    browseLoading.value.subs = true;

    try {
        const url = `/manage/browse/applications/${appId}/subscriptions`
            + `?environment=${browseEnv.value}&organization_id=${encodeURIComponent(orgId)}`;
        const data = await fetchBrowse(url);
        browseSubs.value = data?.subscriptions ?? [];

        // Auto-select when only one sub matches — the most common case
        // since each customer-org tends to have a single subscription
        // per app. The user can click "Change" to reveal the dropdown.
        if (browseSubs.value.length === 1) {
            selectedSubId.value = browseSubs.value[0].id;
        }
    } finally {
        browseLoading.value.subs = false;
    }
}

watch(selectedAppId, (appId) => loadOrgs(appId));

watch(selectedOrgId, (orgId) => loadSubs(selectedAppId.value, orgId));

// Auto-fill all six form fields when the user lands on a complete (app,
// org, sub) selection. Persists the *developer* org id (not the synthetic
// customer slug) so the controller can FK the Application correctly. The
// customer-org name is still surfaced via the subscription_name field.
watch(selectedSubId, (subId) => {
    if (!subId) {
        return;
    }

    const sub = browseSubs.value.find((s) => s.id === subId);

    if (!sub || !selectedApp.value) {
        return;
    }

    newSub.application_id = selectedApp.value.id;
    newSub.application_name = selectedApp.value.name;
    newSub.organization_id = sub.developer_organization_id;
    newSub.organization_name = sub.developer_organization_name;
    newSub.subscription_id = sub.id;
    newSub.subscription_name = sub.name;
    newSub.environment = browseEnv.value;
});

watch(dialogOpen, (open) => {
    if (open && activeTab.value === 'browse' && !browseApps.value.length) {
        loadApps();
    }
});

watch(browseEnv, (env) => {
    newSub.environment = env;

    if (activeTab.value === 'browse') {
        loadApps();
    }
});

const tabs: { id: TabId; label: string }[] = [
    { id: 'browse', label: 'Browse' },
    { id: 'url', label: 'Paste URL' },
    { id: 'manual', label: 'Manual IDs' },
];

const browseCanSave = computed(
    () => !!(selectedApp.value && selectedOrg.value && selectedSub.value && newSub.subscription_name),
);

// Shared "type a nickname / org / app / env" form-fields block used by both
// the Paste URL and Manual IDs tabs. Defined as a render-function component
// so it stays a single-block widget and the parent template stays compact.
const ManualNickname = defineComponent({
    name: 'ManualNickname',
    props: { form: { type: Object, required: true } },
    setup(props) {
        const setVal = (key: string, v: string | number) => {
            (props.form as any)[key] = String(v);
        };

        return () =>
            h('div', { class: 'grid grid-cols-2 gap-2' }, [
                h('div', { class: 'col-span-2 space-y-1' }, [
                    h(Label, { class: 'text-xs uppercase' }, () => 'Subscription nickname'),
                    h(Input, {
                        modelValue: props.form.subscription_name,
                        'onUpdate:modelValue': (v: string | number) => setVal('subscription_name', v),
                    }),
                ]),
                h('div', { class: 'space-y-1' }, [
                    h(Label, { class: 'text-xs uppercase' }, () => 'Org name (optional)'),
                    h(Input, {
                        modelValue: props.form.organization_name,
                        'onUpdate:modelValue': (v: string | number) => setVal('organization_name', v),
                    }),
                ]),
                h('div', { class: 'space-y-1' }, [
                    h(Label, { class: 'text-xs uppercase' }, () => 'App name (optional)'),
                    h(Input, {
                        modelValue: props.form.application_name,
                        'onUpdate:modelValue': (v: string | number) => setVal('application_name', v),
                    }),
                ]),
                h('div', { class: 'col-span-2 space-y-1' }, [
                    h(Label, { class: 'text-xs uppercase' }, () => 'Environment'),
                    h(
                        'select',
                        {
                            value: props.form.environment,
                            class: 'border-input bg-background h-9 w-full rounded-md border px-2 text-sm',
                            onChange: (e: Event) => {
                                (props.form as any).environment = (e.target as HTMLSelectElement).value;
                            },
                        },
                        [
                            h('option', { value: 'production' }, 'production'),
                            h('option', { value: 'staging' }, 'staging'),
                        ],
                    ),
                ]),
            ]);
    },
});
</script>

<template>
    <Head title="Manage" />

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-4 p-4 md:p-6">
        <header class="flex items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Manage</h1>
                <p class="text-muted-foreground text-sm">
                    Organizations, applications, and BookingExperts subscriptions to scrape.
                </p>
                <p class="text-muted-foreground mt-1 text-xs">
                    <span class="text-foreground font-medium">{{ totals.organizations }}</span>
                    org<span v-if="totals.organizations !== 1">s</span>
                    ·
                    <span class="text-foreground font-medium">{{ totals.applications }}</span>
                    app<span v-if="totals.applications !== 1">s</span>
                    ·
                    <span class="text-foreground font-medium">{{ totals.subscriptions }}</span>
                    subscription<span v-if="totals.subscriptions !== 1">s</span>
                    ·
                    <span class="text-foreground font-medium">{{ totals.auto_scrape_on }}</span>
                    auto-scrape on
                    <span v-if="hasFilter" class="text-muted-foreground">
                        — showing
                        <span class="text-foreground font-medium">{{ visibleSubCount }}</span>
                        match<span v-if="visibleSubCount !== 1">es</span>
                    </span>
                </p>
            </div>
            <Dialog v-model:open="dialogOpen">
                <DialogTrigger as-child>
                    <Button>
                        <Plus class="mr-1 size-4" /> Add subscription
                    </Button>
                </DialogTrigger>
                <DialogContent class="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Add a subscription</DialogTitle>
                        <DialogDescription>
                            Browse what your account can see, paste a URL, or enter IDs manually.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="border-border bg-muted/40 flex rounded-md border p-1">
                        <button
                            v-for="tab in tabs"
                            :key="tab.id"
                            type="button"
                            class="flex-1 rounded px-2 py-1 text-sm capitalize transition-colors"
                            :class="
                                activeTab === tab.id
                                    ? 'bg-background shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground'
                            "
                            @click="
                                activeTab = tab.id;
                                if (tab.id === 'browse' && !browseApps.length) loadApps();
                            "
                        >
                            {{ tab.label }}
                        </button>
                    </div>

                    <!-- BROWSE -->
                    <div v-if="activeTab === 'browse'" class="space-y-3">
                        <div class="flex items-center gap-2">
                            <Label class="text-xs uppercase">Environment</Label>
                            <Select v-model="browseEnv">
                                <SelectTrigger class="h-8 w-[160px]">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="production">production</SelectItem>
                                    <SelectItem value="staging">staging</SelectItem>
                                </SelectContent>
                            </Select>
                            <Button
                                variant="ghost"
                                size="sm"
                                :disabled="browseLoading.apps || !!browseRequiresSessionFor"
                                @click="loadApps"
                            >
                                <RefreshCw class="size-4" :class="browseLoading.apps && 'animate-spin'" />
                            </Button>
                        </div>

                        <Alert v-if="browseRequiresSessionFor" variant="default">
                            <KeyRound />
                            <AlertDescription>
                                <p>
                                    No active session for
                                    <strong>{{ browseRequiresSessionFor }}</strong>.
                                </p>
                                <a
                                    :href="browseAuthHref"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-primary inline-flex items-center gap-1 text-xs font-medium underline underline-offset-2"
                                >
                                    Authenticate now <ExternalLink class="size-3" />
                                </a>
                            </AlertDescription>
                        </Alert>

                        <Alert v-else-if="browseError" variant="destructive">
                            <AlertCircle />
                            <AlertDescription>{{ browseError }}</AlertDescription>
                        </Alert>

                        <div v-if="!browseRequiresSessionFor" class="grid gap-3">
                            <div class="space-y-1">
                                <Label class="text-xs uppercase">Application</Label>
                                <div class="flex items-center gap-2">
                                    <Select v-model="selectedAppId" :disabled="browseLoading.apps">
                                        <SelectTrigger class="h-9 flex-1">
                                            <SelectValue
                                                :placeholder="
                                                    browseLoading.apps
                                                        ? 'Loading applications…'
                                                        : browseApps.length
                                                          ? 'Pick an application'
                                                          : 'No applications found'
                                                "
                                            />
                                        </SelectTrigger>
                                        <SelectContent class="max-h-72">
                                            <SelectItem
                                                v-for="app in browseApps"
                                                :key="app.id"
                                                :value="app.id"
                                            >
                                                {{ app.name }}
                                                <span class="text-muted-foreground ml-1 text-xs">
                                                    ({{ app.organization_name }})
                                                </span>
                                                <span class="text-muted-foreground ml-1 font-mono text-[10px]">
                                                    {{ app.id }}
                                                </span>
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <Loader2 v-if="browseLoading.apps" class="text-muted-foreground size-4 animate-spin" />
                                </div>
                            </div>

                            <div class="space-y-1">
                                <Label class="text-xs uppercase">Organization</Label>
                                <div class="flex items-center gap-2">
                                    <Select
                                        v-model="selectedOrgId"
                                        :disabled="!selectedApp || browseLoading.orgs"
                                    >
                                        <SelectTrigger class="h-9 flex-1">
                                            <SelectValue
                                                :placeholder="
                                                    !selectedApp
                                                        ? 'Pick an application first'
                                                        : browseLoading.orgs
                                                          ? 'Loading organizations…'
                                                          : browseOrgs.length
                                                            ? 'Pick an organization'
                                                            : 'No organizations found'
                                                "
                                            />
                                        </SelectTrigger>
                                        <SelectContent class="max-h-72">
                                            <SelectItem
                                                v-for="org in browseOrgs"
                                                :key="org.organization_id"
                                                :value="org.organization_id"
                                            >
                                                {{ org.organization_name }}
                                                <span
                                                    v-if="org.subscription_count > 1"
                                                    class="text-muted-foreground ml-1 text-xs"
                                                >
                                                    ({{ org.subscription_count }} subscriptions)
                                                </span>
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <Loader2 v-if="browseLoading.orgs" class="text-muted-foreground size-4 animate-spin" />
                                </div>
                            </div>

                            <div class="space-y-1">
                                <Label class="text-xs uppercase">Subscription</Label>

                                <p
                                    v-if="!showSubDropdown && selectedSub && !browseLoading.subs"
                                    class="text-muted-foreground text-sm"
                                >
                                    Auto-selected:
                                    <span class="text-foreground font-medium">{{ selectedSub.name }}</span>
                                    ·
                                    <button
                                        type="button"
                                        class="hover:text-foreground underline underline-offset-2"
                                        @click="subDropdownForced = true"
                                    >
                                        Change
                                    </button>
                                </p>

                                <div v-else class="flex items-center gap-2">
                                    <Select
                                        v-model="selectedSubId"
                                        :disabled="!selectedOrg || browseLoading.subs"
                                    >
                                        <SelectTrigger class="h-9 flex-1">
                                            <SelectValue
                                                :placeholder="
                                                    !selectedOrg
                                                        ? 'Pick an organization first'
                                                        : browseLoading.subs
                                                          ? 'Loading subscriptions…'
                                                          : browseSubs.length
                                                            ? 'Pick a subscription'
                                                            : 'No subscriptions found'
                                                "
                                            />
                                        </SelectTrigger>
                                        <SelectContent class="max-h-72">
                                            <SelectItem
                                                v-for="sub in browseSubs"
                                                :key="sub.id"
                                                :value="sub.id"
                                            >
                                                {{ sub.name }}
                                                <span class="text-muted-foreground ml-1 font-mono text-[10px]">
                                                    {{ sub.id }}
                                                </span>
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <Loader2 v-if="browseLoading.subs" class="text-muted-foreground size-4 animate-spin" />
                                </div>
                            </div>

                            <div v-if="selectedSub" class="space-y-1">
                                <Label class="text-xs uppercase">Display name</Label>
                                <Input
                                    v-model="newSub.subscription_name"
                                    placeholder="Pre-filled from BookingExperts"
                                />
                                <p
                                    v-if="newSub.errors.subscription_name"
                                    class="text-destructive text-xs"
                                >
                                    {{ newSub.errors.subscription_name }}
                                </p>
                            </div>
                        </div>

                        <DialogFooter>
                            <Button variant="ghost" @click="dialogOpen = false">Cancel</Button>
                            <Button
                                :disabled="!browseCanSave || newSub.processing"
                                @click="submitNew"
                            >
                                <Loader2
                                    v-if="newSub.processing"
                                    class="mr-1 size-4 animate-spin"
                                />
                                Save
                            </Button>
                        </DialogFooter>
                    </div>

                    <!-- URL -->
                    <div v-else-if="activeTab === 'url'" class="space-y-3">
                        <div class="space-y-1">
                            <Label class="text-xs uppercase">BookingExperts logs URL</Label>
                            <Input
                                v-model="newSub.url"
                                placeholder="https://app.bookingexperts.com/organizations/.../logs"
                            />
                            <p v-if="newSub.errors.url" class="text-destructive text-xs">{{ newSub.errors.url }}</p>
                        </div>
                        <ManualNickname :form="newSub" />
                        <DialogFooter>
                            <Button variant="ghost" @click="dialogOpen = false">Cancel</Button>
                            <Button :disabled="newSub.processing" @click="submitNew">Save</Button>
                        </DialogFooter>
                    </div>

                    <!-- MANUAL -->
                    <div v-else class="space-y-3">
                        <div class="grid grid-cols-3 gap-2">
                            <div class="space-y-1">
                                <Label class="text-xs uppercase">Org ID</Label>
                                <Input v-model="newSub.organization_id" />
                            </div>
                            <div class="space-y-1">
                                <Label class="text-xs uppercase">App ID</Label>
                                <Input v-model="newSub.application_id" />
                            </div>
                            <div class="space-y-1">
                                <Label class="text-xs uppercase">Subscription ID</Label>
                                <Input v-model="newSub.subscription_id" />
                            </div>
                        </div>
                        <ManualNickname :form="newSub" />
                        <DialogFooter>
                            <Button variant="ghost" @click="dialogOpen = false">Cancel</Button>
                            <Button :disabled="newSub.processing" @click="submitNew">Save</Button>
                        </DialogFooter>
                    </div>
                </DialogContent>
            </Dialog>
        </header>

        <!--
            Sticky bulk-actions toolbar (F17). Slides in once any
            subscription is selected. `sticky top-0 z-10` makes it
            ride above the org cards while the user scrolls; the
            shadow + backdrop blur keep the underlying card edges
            visually separated.
        -->
        <div
            v-if="selectedCount > 0"
            class="bg-background/95 border-border sticky top-0 z-10 flex flex-wrap items-center gap-2 rounded-md border p-2 shadow-sm backdrop-blur"
            data-testid="manage-bulk-toolbar"
        >
            <span class="text-sm font-medium">
                {{ selectedCount }} selected
            </span>
            <Button variant="ghost" size="sm" @click="clearSelection">
                <X class="mr-1 size-4" /> Clear
            </Button>

            <span class="text-muted-foreground mx-1">·</span>

            <Button
                variant="outline"
                size="sm"
                :disabled="bulkProcessing"
                @click="bulkPause"
            >
                <Pause class="mr-1 size-4" /> Pause all
            </Button>
            <Button
                variant="outline"
                size="sm"
                :disabled="bulkProcessing"
                @click="bulkResume"
            >
                <Play class="mr-1 size-4" /> Resume all
            </Button>
            <Button
                variant="outline"
                size="sm"
                :disabled="bulkProcessing"
                @click="bulkScrape"
            >
                <RefreshCw class="mr-1 size-4" :class="bulkProcessing && 'animate-spin'" />
                Scrape now
            </Button>
            <Button
                variant="outline"
                size="sm"
                :disabled="bulkProcessing"
                @click="budgetDialogOpen = true"
            >
                <Settings2 class="mr-1 size-4" /> Edit budgets…
            </Button>

            <div class="ml-auto">
                <Button
                    variant="destructive"
                    size="sm"
                    :disabled="bulkProcessing"
                    @click="deleteDialogOpen = true"
                >
                    <Trash2 class="mr-1 size-4" /> Delete selected
                </Button>
            </div>
        </div>

        <!--
            Filter toolbar. Hidden when the account has no
            subscriptions yet (the empty-state card below is more
            helpful than a search box over zero rows).
        -->
        <div
            v-if="totals.subscriptions > 0"
            class="border-border bg-background flex flex-wrap items-center gap-2 rounded-md border p-2"
            data-testid="manage-filter-toolbar"
        >
            <label class="flex items-center gap-1 pr-2 text-xs">
                <Checkbox
                    :model-value="allVisibleSelected"
                    aria-label="Select all visible subscriptions"
                    @update:model-value="toggleSelectAllVisible"
                />
                <span class="text-muted-foreground uppercase">All visible</span>
            </label>
            <div class="relative min-w-[220px] flex-1">
                <Search class="text-muted-foreground pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2" />
                <input
                    type="search"
                    placeholder="Search by name or ID…"
                    :value="searchQuery"
                    class="border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border pl-8 pr-8 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1"
                    aria-label="Filter subscriptions"
                    @input="onSearchInput(($event.target as HTMLInputElement).value)"
                />
                <button
                    v-if="hasFilter"
                    type="button"
                    class="text-muted-foreground hover:text-foreground absolute right-2 top-1/2 -translate-y-1/2"
                    aria-label="Clear search"
                    @click="clearSearch"
                >
                    <X class="size-4" />
                </button>
            </div>

            <div class="flex items-center gap-2">
                <Label class="text-muted-foreground text-xs uppercase">Sort</Label>
                <Select :model-value="sortMode" @update:model-value="(v) => onSortChange(v as SortMode)">
                    <SelectTrigger class="h-9 w-[160px]">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="opt in SORT_OPTIONS"
                            :key="opt.value"
                            :value="opt.value"
                        >
                            <div class="flex flex-col">
                                <span>{{ opt.label }}</span>
                                <span class="text-muted-foreground text-[10px]">{{ opt.hint }}</span>
                            </div>
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <Button
                variant="outline"
                size="sm"
                class="ml-auto"
                @click="toggleExpandAll"
            >
                <ChevronDown v-if="!expandAll" class="mr-1 size-4" />
                <ChevronRight v-else class="mr-1 size-4" />
                {{ expandAll ? 'Collapse all' : 'Expand all' }}
            </Button>
        </div>

        <Card v-if="!sessionsActive">
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    <KeyRound class="size-5" /> No active BookingExperts sessions
                </CardTitle>
                <CardDescription>
                    Add at least one session before subscriptions can be scraped or browsed.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Button as-child>
                    <Link href="/authenticate">Go to Sessions</Link>
                </Button>
            </CardContent>
        </Card>

        <!--
            Empty filter-state. The unfiltered empty state below
            (`!organizations.length` when `!totals.subscriptions`)
            tells the user to add a subscription; this one tells them
            their filter is too narrow.
        -->
        <Card v-if="hasFilter && !organizations.length && totals.subscriptions > 0">
            <CardHeader>
                <CardTitle class="text-base">No subscriptions match “{{ searchQuery }}”</CardTitle>
                <CardDescription>
                    Try a different query, or
                    <button class="underline underline-offset-2" @click="clearSearch">clear the search</button>.
                </CardDescription>
            </CardHeader>
        </Card>

        <Card v-if="!organizations.length">
            <CardHeader>
                <CardTitle>No subscriptions yet</CardTitle>
                <CardDescription>
                    Click <strong>Add subscription</strong> above to get started. With an active
                    session, the Browse tab lets you pick from everything your account can see.
                </CardDescription>
            </CardHeader>
        </Card>

        <!--
            Retention shorten confirmation. Surfaces ONLY when the
            operator types a tighter window than the currently
            persisted one (or sets one for the first time on a sub
            with rows already older than the new cutoff). The hint
            count comes from the server payload and is a best-effort
            estimate — the actual nightly run is the source of
            truth for the precise delete count.
        -->
        <Dialog
            :open="retentionConfirm !== null"
            @update:open="(open) => { if (!open) dismissRetentionShorten(); }"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Shorten retention window?</DialogTitle>
                    <DialogDescription v-if="retentionConfirm">
                        Setting retention to
                        <strong>{{ retentionConfirm.nextValue }} days</strong>
                        on <strong>{{ retentionConfirm.sub.name }}</strong> will
                        delete approximately
                        <strong>{{ retentionConfirm.impactCount.toLocaleString() }}</strong>
                        rows on the next nightly run (03:00 UTC). This action is
                        irreversible — archived rows are not affected.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="dismissRetentionShorten">Cancel</Button>
                    <Button @click="confirmRetentionShorten">Confirm</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Card v-for="org in organizations" :key="org.id">
            <CardHeader class="pb-2">
                <CardTitle class="flex items-center gap-2 text-base">
                    <Building2 class="size-5" /> {{ org.name }}
                    <Badge variant="outline" class="font-mono text-xs">{{ org.id }}</Badge>
                </CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
                <div
                    v-for="app in org.applications"
                    :key="app.id"
                    class="border-border space-y-2 rounded-md border p-3"
                >
                    <div class="flex items-center gap-2">
                        <h3 class="text-sm font-medium">{{ app.name }}</h3>
                        <Badge variant="outline" class="font-mono text-xs">{{ app.id }}</Badge>
                    </div>
                    <div
                        v-for="sub in app.subscriptions"
                        :key="sub.id"
                        class="bg-muted/30 space-y-2 rounded-md p-3"
                        :class="isSelected(sub.id) && 'ring-primary/40 ring-2 ring-offset-1'"
                        data-testid="manage-sub-row"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <!--
                                Per-row checkbox lives outside the
                                expand/collapse trigger because nesting
                                an <input> inside the trigger would
                                make every checkbox click also toggle
                                the expanded state.
                            -->
                            <label class="flex items-center" @click.stop>
                                <Checkbox
                                    :model-value="isSelected(sub.id)"
                                    :aria-label="`Select ${sub.name}`"
                                    :data-testid="`manage-sub-checkbox-${sub.id}`"
                                    @update:model-value="toggleSelected(sub.id)"
                                />
                            </label>
                            <button
                                type="button"
                                class="flex flex-1 items-center gap-2 text-left"
                                :aria-expanded="isExpanded(sub.id)"
                                @click="toggleExpanded(sub.id)"
                            >
                                <ChevronDown
                                    v-if="isExpanded(sub.id)"
                                    class="text-muted-foreground size-4 shrink-0"
                                />
                                <ChevronRight v-else class="text-muted-foreground size-4 shrink-0" />
                                <div class="flex flex-col">
                                    <span class="text-sm font-medium">{{ sub.name }}</span>
                                    <span class="text-muted-foreground flex items-center gap-2 text-xs">
                                        <span class="font-mono">{{ sub.id }}</span>
                                        <Badge variant="outline" class="px-1 py-0 text-[10px]">
                                            {{ sub.environment }}
                                        </Badge>
                                        <span>·</span>
                                        <span>last scrape: {{ formatLastScraped(sub.last_scraped_at) }}</span>
                                    </span>
                                </div>
                            </button>
                            <div class="flex flex-wrap items-center gap-3 text-sm">
                                <label class="flex items-center gap-2">
                                    <Switch
                                        :model-value="sub.auto_scrape"
                                        @update:model-value="toggleAuto(sub)"
                                    />
                                    <span class="text-xs uppercase">Auto-scrape</span>
                                </label>
                                <div class="flex items-center gap-1">
                                    <Input
                                        type="number"
                                        min="1"
                                        max="1440"
                                        class="h-8 w-20"
                                        :model-value="sub.scrape_interval_minutes"
                                        @change="(e: Event) => updateInterval(sub, Number((e.target as HTMLInputElement).value))"
                                    />
                                    <span class="text-muted-foreground text-xs">min</span>
                                </div>
                                <Button size="sm" @click="scrapeNow(sub)">
                                    <Play class="mr-1 size-4" /> Scrape now
                                </Button>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    class="text-destructive"
                                    @click="deleteSub(sub)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </div>

                        <!--
                            Budget + concurrency knobs collapse by default. The
                            common-case controls (auto-scrape, interval, scrape
                            now, delete) live in the header row above and stay
                            always-visible; this block is the "edit advanced
                            settings" surface that only shows when expanded.
                        -->
                        <div v-show="isExpanded(sub.id)" class="space-y-2">
                        <div
                            class="border-border/60 grid grid-cols-1 gap-3 border-t pt-2 sm:grid-cols-3"
                        >
                            <div class="space-y-1">
                                <Label
                                    :for="`max-pages-${sub.id}`"
                                    class="text-muted-foreground text-[10px] uppercase tracking-wide"
                                >
                                    Max pages per scrape
                                </Label>
                                <div class="flex items-center gap-1">
                                    <Input
                                        :id="`max-pages-${sub.id}`"
                                        type="number"
                                        min="1"
                                        max="5000"
                                        class="h-8 w-24"
                                        :model-value="sub.max_pages_per_scrape"
                                        @change="(e: Event) => updateBudget(sub, 'max_pages_per_scrape', Number((e.target as HTMLInputElement).value))"
                                    />
                                    <span class="text-muted-foreground text-xs">pages</span>
                                </div>
                                <p class="text-muted-foreground text-[10px]">
                                    Hard cap; 50 entries/page typical.
                                </p>
                            </div>

                            <div class="space-y-1">
                                <Label
                                    :for="`lookback-${sub.id}`"
                                    class="text-muted-foreground text-[10px] uppercase tracking-wide"
                                >
                                    Lookback days (first scrape only)
                                </Label>
                                <div class="flex items-center gap-1">
                                    <Input
                                        :id="`lookback-${sub.id}`"
                                        type="number"
                                        min="1"
                                        max="365"
                                        class="h-8 w-24"
                                        :model-value="sub.lookback_days_first_scrape"
                                        @change="(e: Event) => updateBudget(sub, 'lookback_days_first_scrape', Number((e.target as HTMLInputElement).value))"
                                    />
                                    <span class="text-muted-foreground text-xs">days</span>
                                </div>
                                <p class="text-muted-foreground text-[10px]">
                                    How far back to fetch on the very first scrape.
                                </p>
                            </div>

                            <div class="space-y-1">
                                <Label
                                    :for="`duration-${sub.id}`"
                                    class="text-muted-foreground text-[10px] uppercase tracking-wide"
                                >
                                    Max scrape duration (minutes)
                                </Label>
                                <div class="flex items-center gap-1">
                                    <Input
                                        :id="`duration-${sub.id}`"
                                        type="number"
                                        min="1"
                                        max="120"
                                        class="h-8 w-24"
                                        :model-value="sub.max_duration_minutes"
                                        @change="(e: Event) => updateBudget(sub, 'max_duration_minutes', Number((e.target as HTMLInputElement).value))"
                                    />
                                    <span class="text-muted-foreground text-xs">min</span>
                                </div>
                                <p class="text-muted-foreground text-[10px]">
                                    Wall-clock budget; jobs abort cleanly when reached.
                                </p>
                            </div>
                        </div>

                        <div
                            class="border-border/60 grid grid-cols-1 gap-3 border-t pt-2 sm:grid-cols-2"
                            data-testid="concurrency-block"
                        >
                            <div class="space-y-1">
                                <Label
                                    :for="`max-concurrent-${sub.id}`"
                                    class="text-muted-foreground text-[10px] uppercase tracking-wide"
                                >
                                    Max concurrent jobs
                                </Label>
                                <div class="flex items-center gap-1">
                                    <Input
                                        :id="`max-concurrent-${sub.id}`"
                                        type="number"
                                        min="1"
                                        max="10"
                                        class="h-8 w-24"
                                        :model-value="sub.max_concurrent_jobs"
                                        @change="(e: Event) => updateBudget(sub, 'max_concurrent_jobs', Number((e.target as HTMLInputElement).value))"
                                    />
                                    <span class="text-muted-foreground text-xs">jobs</span>
                                </div>
                                <p class="text-muted-foreground text-[10px]">
                                    Cap on jobs running at the same time for this
                                    subscription. 1 preserves today's behaviour. Raise
                                    when a single run can't keep up (e.g. EuroParcs).
                                </p>
                            </div>

                            <div class="space-y-1">
                                <Label
                                    :for="`spacing-${sub.id}`"
                                    class="text-muted-foreground text-[10px] uppercase tracking-wide"
                                >
                                    Spacing between jobs (minutes)
                                </Label>
                                <div class="flex items-center gap-1">
                                    <Input
                                        :id="`spacing-${sub.id}`"
                                        type="number"
                                        min="1"
                                        max="120"
                                        class="h-8 w-24"
                                        :model-value="sub.job_spacing_minutes"
                                        @change="(e: Event) => updateBudget(sub, 'job_spacing_minutes', Number((e.target as HTMLInputElement).value))"
                                    />
                                    <span class="text-muted-foreground text-xs">min</span>
                                </div>
                                <p class="text-muted-foreground text-[10px]">
                                    Minimum wait before a new concurrent job can be
                                    dispatched. A freshly-started job reserves the slot
                                    for this long. 10 minutes is a reasonable default.
                                </p>
                            </div>
                        </div>

                        <div
                            class="border-border/60 grid grid-cols-1 gap-3 border-t pt-2 sm:grid-cols-2"
                            data-testid="retry-block"
                        >
                            <div class="space-y-1">
                                <Label
                                    :for="`token-echo-${sub.id}`"
                                    class="text-muted-foreground text-[10px] uppercase tracking-wide"
                                >
                                    Token-echo retry limit
                                </Label>
                                <div class="flex items-center gap-1">
                                    <Input
                                        :id="`token-echo-${sub.id}`"
                                        type="number"
                                        min="1"
                                        max="1000"
                                        class="h-8 w-24"
                                        :model-value="sub.token_echo_max_attempts"
                                        @change="(e: Event) => updateBudget(sub, 'token_echo_max_attempts', Number((e.target as HTMLInputElement).value))"
                                    />
                                    <span class="text-muted-foreground text-xs">attempts</span>
                                </div>
                                <p class="text-muted-foreground text-[10px]">
                                    Cap on retries when BookingExperts keeps returning
                                    the same next-token (the "live tip" signal). 100
                                    = 1 initial + 99 retries at 3 s each ≈ 5 min on
                                    full exhaust. Raise for tail-quiet subscriptions
                                    where you want the scraper to wait longer for
                                    new activity before declaring caught-up.
                                </p>
                            </div>
                        </div>

                        <!--
                            Data-lifecycle (G19 + G20): per-sub retention
                            window for the hot Postgres table and
                            per-sub archive cutover to Hetzner Object
                            Storage. Both inputs accept an empty value =
                            "feature off" (keep forever / never archive),
                            which is also the historical default that
                            applies to every existing subscription until
                            an operator explicitly opts in.
                        -->
                        <div
                            class="border-border/60 grid grid-cols-1 gap-3 border-t pt-2 sm:grid-cols-2"
                            data-testid="lifecycle-block"
                        >
                            <div class="space-y-1">
                                <Label
                                    :for="`retention-${sub.id}`"
                                    class="text-muted-foreground text-[10px] uppercase tracking-wide"
                                >
                                    Retention (days)
                                </Label>
                                <div class="flex items-center gap-1">
                                    <Input
                                        :id="`retention-${sub.id}`"
                                        type="number"
                                        min="1"
                                        max="3650"
                                        class="h-8 w-24"
                                        :placeholder="'∞'"
                                        :model-value="sub.retention_days ?? ''"
                                        @change="(e: Event) => updateLifecycle(sub, 'retention_days', (e.target as HTMLInputElement).value)"
                                    />
                                    <span class="text-muted-foreground text-xs">days</span>
                                </div>
                                <p class="text-muted-foreground text-[10px]">
                                    <span v-if="sub.retention_days === null">
                                        Empty = keep forever. The nightly retention
                                        sweep skips subscriptions with no window set.
                                    </span>
                                    <span v-else-if="sub.lifecycle_counts.hot_rows_older_than_retention > 0">
                                        Next nightly run will prune
                                        <span class="text-foreground font-medium">
                                            ~{{ sub.lifecycle_counts.hot_rows_older_than_retention.toLocaleString() }}
                                        </span>
                                        rows older than {{ sub.retention_days }} days.
                                    </span>
                                    <span v-else>
                                        No rows currently exceed the
                                        {{ sub.retention_days }}-day window.
                                    </span>
                                </p>
                            </div>

                            <div class="space-y-1">
                                <Label
                                    :for="`archive-${sub.id}`"
                                    class="text-muted-foreground text-[10px] uppercase tracking-wide"
                                >
                                    Archive after (days)
                                </Label>
                                <div class="flex items-center gap-1">
                                    <Input
                                        :id="`archive-${sub.id}`"
                                        type="number"
                                        min="7"
                                        max="3650"
                                        class="h-8 w-24"
                                        :placeholder="'never'"
                                        :model-value="sub.archive_after_days ?? ''"
                                        @change="(e: Event) => updateLifecycle(sub, 'archive_after_days', (e.target as HTMLInputElement).value)"
                                    />
                                    <span class="text-muted-foreground text-xs">days</span>
                                </div>
                                <p class="text-muted-foreground text-[10px]">
                                    <span v-if="sub.archive_after_days === null">
                                        Empty = never archive. Otherwise rows older than this
                                        window get moved to compressed JSONL on Hetzner Object
                                        Storage; the Logs UI still reads them transparently.
                                    </span>
                                    <span v-else-if="sub.lifecycle_counts.archived_row_count > 0">
                                        <span class="text-foreground font-medium">
                                            {{ sub.lifecycle_counts.archived_row_count.toLocaleString() }}
                                        </span>
                                        rows already archived to cold storage.
                                    </span>
                                    <span v-else>
                                        No rows have been archived yet for this subscription.
                                    </span>
                                </p>
                            </div>
                        </div>
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!--
            Bulk-edit budgets dialog. Per-field "Apply to all"
            checkboxes give the operator opt-in control over which
            columns to push; an unchecked field is omitted from the
            payload so the server's "sometimes" validator leaves the
            column untouched.
        -->
        <Dialog v-model:open="budgetDialogOpen">
            <DialogContent class="sm:max-w-2xl" data-testid="bulk-budget-dialog">
                <DialogHeader>
                    <DialogTitle>Edit budgets for {{ selectedCount }} subscriptions</DialogTitle>
                    <DialogDescription>
                        Tick "Apply" beside the fields you want to change. Unticked rows are left
                        as-is on each subscription.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="border-border space-y-1 rounded-md border p-2">
                        <label class="flex items-center gap-2 text-xs uppercase">
                            <Checkbox v-model="budgetForm.apply.auto_scrape" />
                            Apply · Auto-scrape
                        </label>
                        <label class="flex items-center gap-2">
                            <Switch v-model="budgetForm.values.auto_scrape" />
                            <span class="text-sm">
                                {{ budgetForm.values.auto_scrape ? 'On (resume)' : 'Off (pause)' }}
                            </span>
                        </label>
                    </div>

                    <div class="border-border space-y-1 rounded-md border p-2">
                        <label class="flex items-center gap-2 text-xs uppercase">
                            <Checkbox v-model="budgetForm.apply.scrape_interval_minutes" />
                            Apply · Interval (min)
                        </label>
                        <Input
                            type="number"
                            min="1"
                            max="1440"
                            v-model.number="budgetForm.values.scrape_interval_minutes"
                        />
                    </div>

                    <div class="border-border space-y-1 rounded-md border p-2">
                        <label class="flex items-center gap-2 text-xs uppercase">
                            <Checkbox v-model="budgetForm.apply.max_pages_per_scrape" />
                            Apply · Max pages
                        </label>
                        <Input
                            type="number"
                            min="1"
                            max="5000"
                            v-model.number="budgetForm.values.max_pages_per_scrape"
                        />
                    </div>

                    <div class="border-border space-y-1 rounded-md border p-2">
                        <label class="flex items-center gap-2 text-xs uppercase">
                            <Checkbox v-model="budgetForm.apply.lookback_days_first_scrape" />
                            Apply · Lookback (days, first scrape)
                        </label>
                        <Input
                            type="number"
                            min="1"
                            max="365"
                            v-model.number="budgetForm.values.lookback_days_first_scrape"
                        />
                    </div>

                    <div class="border-border space-y-1 rounded-md border p-2">
                        <label class="flex items-center gap-2 text-xs uppercase">
                            <Checkbox v-model="budgetForm.apply.max_duration_minutes" />
                            Apply · Max duration (min)
                        </label>
                        <Input
                            type="number"
                            min="1"
                            max="120"
                            v-model.number="budgetForm.values.max_duration_minutes"
                        />
                    </div>

                    <div class="border-border space-y-1 rounded-md border p-2">
                        <label class="flex items-center gap-2 text-xs uppercase">
                            <Checkbox v-model="budgetForm.apply.max_concurrent_jobs" />
                            Apply · Max concurrent jobs
                        </label>
                        <Input
                            type="number"
                            min="1"
                            max="10"
                            v-model.number="budgetForm.values.max_concurrent_jobs"
                        />
                    </div>

                    <div class="border-border space-y-1 rounded-md border p-2">
                        <label class="flex items-center gap-2 text-xs uppercase">
                            <Checkbox v-model="budgetForm.apply.job_spacing_minutes" />
                            Apply · Job spacing (min)
                        </label>
                        <Input
                            type="number"
                            min="1"
                            max="120"
                            v-model.number="budgetForm.values.job_spacing_minutes"
                        />
                    </div>

                    <div class="border-border space-y-1 rounded-md border p-2">
                        <label class="flex items-center gap-2 text-xs uppercase">
                            <Checkbox v-model="budgetForm.apply.token_echo_max_attempts" />
                            Apply · Token-echo retries
                        </label>
                        <Input
                            type="number"
                            min="1"
                            max="1000"
                            v-model.number="budgetForm.values.token_echo_max_attempts"
                        />
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="ghost" @click="budgetDialogOpen = false">Cancel</Button>
                    <Button :disabled="bulkProcessing" @click="submitBulkBudget">
                        <Loader2 v-if="bulkProcessing" class="mr-1 size-4 animate-spin" />
                        Apply to {{ selectedCount }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!--
            Confirm-delete dialog. Lists the names being deleted so the
            operator can sanity-check the selection one last time. We
            cap the visible names at 10 — anything beyond that gets a
            "…and N more" line because a 200-row scroll inside a
            modal is its own UX problem.
        -->
        <Dialog v-model:open="deleteDialogOpen">
            <DialogContent class="sm:max-w-lg" data-testid="bulk-delete-dialog">
                <DialogHeader>
                    <DialogTitle>Delete {{ selectedCount }} subscriptions?</DialogTitle>
                    <DialogDescription>
                        This removes the rows and every log message attached to them. The
                        operation is not reversible.
                    </DialogDescription>
                </DialogHeader>

                <ul class="bg-muted/30 max-h-60 overflow-y-auto rounded-md p-2 text-sm">
                    <li v-for="sub in selectedSubs.slice(0, 10)" :key="sub.id" class="flex justify-between gap-2 py-0.5">
                        <span class="truncate">{{ sub.name }}</span>
                        <span class="text-muted-foreground font-mono text-xs">{{ sub.id }}</span>
                    </li>
                    <li v-if="selectedSubs.length > 10" class="text-muted-foreground py-0.5 text-xs italic">
                        …and {{ selectedSubs.length - 10 }} more
                    </li>
                </ul>

                <DialogFooter>
                    <Button variant="ghost" @click="deleteDialogOpen = false">Cancel</Button>
                    <Button variant="destructive" :disabled="bulkProcessing" @click="submitBulkDelete">
                        <Loader2 v-if="bulkProcessing" class="mr-1 size-4 animate-spin" />
                        Delete {{ selectedCount }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>

