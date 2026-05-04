<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    AlertCircle,
    Building2,
    ExternalLink,
    KeyRound,
    Loader2,
    Play,
    Plus,
    RefreshCw,
    Trash2,
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

defineProps<{
    organizations: OrgRow[];
    sessionsActive: number;
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Manage', href: '/manage' }] } });

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
    | 'job_spacing_minutes';

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

        <Card v-if="!organizations.length">
            <CardHeader>
                <CardTitle>No subscriptions yet</CardTitle>
                <CardDescription>
                    Click <strong>Add subscription</strong> above to get started. With an active
                    session, the Browse tab lets you pick from everything your account can see.
                </CardDescription>
            </CardHeader>
        </Card>

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
                    >
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <div class="flex flex-col">
                                    <span class="text-sm font-medium">{{ sub.name }}</span>
                                    <span class="text-muted-foreground font-mono text-xs">
                                        {{ sub.id }} · {{ sub.environment }}
                                    </span>
                                </div>
                            </div>
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
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>

