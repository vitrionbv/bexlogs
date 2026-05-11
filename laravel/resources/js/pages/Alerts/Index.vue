<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    BellRing,
    CheckCircle2,
    Clock,
    Mail,
    Pencil,
    Plus,
    Send,
    Slack,
    Trash2,
    Webhook,
    XCircle,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
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
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
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

// ─── Types ───────────────────────────────────────────────────────────────────

type ChannelKind = 'slack' | 'webhook' | 'email';

interface ChannelRef {
    id: number;
    name: string;
    kind: ChannelKind;
    dedupe_window_seconds?: number;
}

interface SavedQueryRow {
    id: number;
    name: string;
    enabled: boolean;
    filter: Record<string, unknown>;
    channels: ChannelRef[];
    updated_at: string | null;
}

interface ChannelRow {
    id: number;
    name: string;
    kind: ChannelKind;
    enabled: boolean;
    /** Server-redacted view — never carries the raw URL hash or HMAC secret. */
    config: Record<string, unknown>;
    updated_at: string | null;
}

interface SubscriptionRef {
    id: string;
    name: string;
    environment: 'production' | 'staging';
}

interface DeliveryRow {
    id: number;
    status: 'queued' | 'sent' | 'failed';
    attempts: number;
    error: string | null;
    created_at: string | null;
    last_attempt_at: string | null;
    title: string;
    channel: { id: number; name: string; kind: ChannelKind } | null;
    saved_query: { id: number; name: string } | null;
}

const props = defineProps<{
    savedQueries: SavedQueryRow[];
    channels: ChannelRow[];
    systemChannelIds: number[];
    recentDeliveries: DeliveryRow[];
    subscriptions: SubscriptionRef[];
    channelKinds: ChannelKind[];
}>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Alerts', href: '/alerts' }] } });

// ─── Tabs ─────────────────────────────────────────────────────────────────────
//
// Three tab segmented control. We keep the control client-side rather
// than URL-driven because a tab switch should NEVER trigger a server
// round-trip — the index endpoint already shipped the data for all
// three sections in one response. URL state for the open tab would
// also fight the Inertia partial reload pattern we use for
// deliveries (see refreshLists below).

type TabKey = 'queries' | 'channels' | 'system';
const activeTab = ref<TabKey>('queries');
const tabs: { value: TabKey; label: string; icon: typeof BellRing }[] = [
    { value: 'queries', label: 'Saved Queries', icon: BellRing },
    { value: 'channels', label: 'Channels', icon: Send },
    { value: 'system', label: 'System Alerts', icon: AlertTriangle },
];

// ─── Saved Queries ────────────────────────────────────────────────────────────

const queryDialogOpen = ref(false);
const queryEditing = ref<SavedQueryRow | null>(null);

interface QueryFormShape {
    name: string;
    enabled: boolean;
    filter: {
        subscription_id?: string;
        environment?: 'production' | 'staging' | '';
        method?: string;
        status_regex?: string;
        action_regex?: string;
        since?: string;
    };
    channels: { id: number; dedupe_window_seconds: number }[];
}

const queryForm = useForm<QueryFormShape>({
    name: '',
    enabled: true,
    filter: { subscription_id: '', environment: '', method: '', status_regex: '', action_regex: '', since: '' },
    channels: [],
});

function openQueryDialog(query?: SavedQueryRow) {
    queryEditing.value = query ?? null;

    queryForm.reset();
    queryForm.clearErrors();
    queryForm.name = query?.name ?? '';
    queryForm.enabled = query?.enabled ?? true;
    queryForm.filter = {
        subscription_id: (query?.filter.subscription_id as string | undefined) ?? '',
        environment: (query?.filter.environment as 'production' | 'staging' | undefined) ?? '',
        method: (query?.filter.method as string | undefined) ?? '',
        status_regex: (query?.filter.status_regex as string | undefined) ?? '',
        action_regex: (query?.filter.action_regex as string | undefined) ?? '',
        since: (query?.filter.since as string | undefined) ?? '',
    };
    queryForm.channels = (query?.channels ?? []).map((c) => ({
        id: c.id,
        dedupe_window_seconds: c.dedupe_window_seconds ?? 60,
    }));
    queryDialogOpen.value = true;
}

function toggleQueryChannel(channelId: number, on: boolean) {
    if (on) {
        if (!queryForm.channels.find((c) => c.id === channelId)) {
            queryForm.channels.push({ id: channelId, dedupe_window_seconds: 60 });
        }
    } else {
        queryForm.channels = queryForm.channels.filter((c) => c.id !== channelId);
    }
}

function isChannelOnQuery(channelId: number): boolean {
    return queryForm.channels.some((c) => c.id === channelId);
}

function setChannelDedupe(channelId: number, value: number) {
    const link = queryForm.channels.find((c) => c.id === channelId);
    if (link) {
        link.dedupe_window_seconds = Math.max(0, Math.min(86400, Math.round(value || 0)));
    }
}

function submitQuery() {
    // Drop empty filter strings client-side so the server never
    // sees `{ method: '' }` — that would round-trip through the
    // jsonb column and surface in the evaluator as a "method must
    // equal empty string" filter, which never matches anything and
    // surprised operators editing existing queries.
    const cleaned: Record<string, string | undefined> = {};
    for (const [key, value] of Object.entries(queryForm.filter)) {
        if (typeof value === 'string' && value.trim() !== '') {
            cleaned[key] = value.trim();
        }
    }
    const payload = {
        name: queryForm.name,
        enabled: queryForm.enabled,
        filter: cleaned,
        channels: queryForm.channels,
    };

    const editing = queryEditing.value;
    const onSuccess = () => {
        queryDialogOpen.value = false;
        toast.success(editing ? 'Saved query updated' : 'Saved query created');
    };
    const opts = { preserveScroll: true, onSuccess };

    if (editing) {
        router.patch(`/alerts/queries/${editing.id}`, payload, opts);
    } else {
        router.post('/alerts/queries', payload, opts);
    }
}

function deleteQuery(query: SavedQueryRow) {
    if (!confirm(`Delete saved query «${query.name}»?`)) {
        return;
    }
    router.delete(`/alerts/queries/${query.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Saved query deleted'),
    });
}

// ─── Channels ─────────────────────────────────────────────────────────────────

const channelDialogOpen = ref(false);
const channelEditing = ref<ChannelRow | null>(null);

interface ChannelFormShape {
    name: string;
    kind: ChannelKind;
    enabled: boolean;
    config: { url: string; secret: string; to_address: string };
}

const channelForm = useForm<ChannelFormShape>({
    name: '',
    kind: 'slack',
    enabled: true,
    config: { url: '', secret: '', to_address: '' },
});

function openChannelDialog(channel?: ChannelRow) {
    channelEditing.value = channel ?? null;

    channelForm.reset();
    channelForm.clearErrors();
    channelForm.name = channel?.name ?? '';
    channelForm.kind = channel?.kind ?? 'slack';
    channelForm.enabled = channel?.enabled ?? true;
    // Important: never pre-fill URL/secret/to_address with the
    // redacted values — submitting them back would overwrite the
    // real encrypted blob with `****` strings. Editing keeps the
    // fields blank; the controller's update path treats empty
    // values as "leave as-is".
    channelForm.config = { url: '', secret: '', to_address: '' };
    channelDialogOpen.value = true;
}

function submitChannel() {
    const editing = channelEditing.value;
    const onSuccess = () => {
        channelDialogOpen.value = false;
        toast.success(editing ? 'Channel updated' : 'Channel created');
    };
    const opts = { preserveScroll: true, onSuccess };

    if (editing) {
        channelForm.patch(`/alerts/channels/${editing.id}`, opts);
    } else {
        channelForm.post('/alerts/channels', opts);
    }
}

function deleteChannel(channel: ChannelRow) {
    if (!confirm(`Delete channel «${channel.name}»? Saved queries linked to it will lose this destination.`)) {
        return;
    }
    router.delete(`/alerts/channels/${channel.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Channel deleted'),
    });
}

const channelKindIcon: Record<ChannelKind, typeof BellRing> = {
    slack: Slack,
    webhook: Webhook,
    email: Mail,
};

const channelKindLabel: Record<ChannelKind, string> = {
    slack: 'Slack webhook',
    webhook: 'Generic webhook',
    email: 'Email',
};

// ─── System alert channels ────────────────────────────────────────────────────
//
// A separate pivot from the per-query channel set so the operator
// can route infrastructure pings to a distinct destination. The form
// is just a list of checkboxes (one per existing channel) submitted
// as a sync — the server replaces the pivot rows in one transaction.

const systemSelected = ref<number[]>([...props.systemChannelIds]);

function toggleSystemChannel(channelId: number, on: boolean) {
    if (on) {
        if (!systemSelected.value.includes(channelId)) {
            systemSelected.value.push(channelId);
        }
    } else {
        systemSelected.value = systemSelected.value.filter((id) => id !== channelId);
    }
}

function saveSystemChannels() {
    router.post(
        '/alerts/system-channels',
        { channel_ids: systemSelected.value },
        {
            preserveScroll: true,
            onSuccess: () => toast.success('System alert channels updated'),
        },
    );
}

const systemDirty = computed(() => {
    const a = [...systemSelected.value].sort();
    const b = [...props.systemChannelIds].sort();
    if (a.length !== b.length) {
        return true;
    }
    return a.some((id, i) => id !== b[i]);
});

// ─── Recent deliveries ────────────────────────────────────────────────────────

const deliveryStatusVariant: Record<DeliveryRow['status'], 'success' | 'warning' | 'destructive'> = {
    sent: 'success',
    queued: 'warning',
    failed: 'destructive',
};

const deliveryStatusIcon: Record<DeliveryRow['status'], typeof CheckCircle2> = {
    sent: CheckCircle2,
    queued: Clock,
    failed: XCircle,
};

function relativeTime(iso: string | null): string {
    if (!iso) {
        return 'never';
    }
    const date = new Date(iso);
    const diff = Date.now() - date.getTime();
    const sec = Math.round(diff / 1000);
    if (sec < 60) return `${sec}s ago`;
    const min = Math.round(sec / 60);
    if (min < 60) return `${min}m ago`;
    const hr = Math.round(min / 60);
    if (hr < 48) return `${hr}h ago`;
    return date.toLocaleDateString();
}
</script>

<template>
    <div>
        <Head title="Alerts" />

        <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
            <header class="flex flex-col gap-2">
                <h1 class="text-2xl font-semibold tracking-tight">Alerts</h1>
                <p class="text-muted-foreground max-w-2xl text-sm">
                    Define filter expressions on incoming log rows and route matches to one or
                    more delivery channels. The system also pages you on infrastructure events
                    (session expiring, repeated failures, quiet subscriptions) through the
                    channels you mark in <strong>System Alerts</strong>.
                </p>
            </header>

            <!-- Tab strip -->
            <div class="bg-muted/40 inline-flex w-fit gap-1 rounded-lg p-1">
                <button
                    v-for="tab in tabs"
                    :key="tab.value"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md px-3.5 py-1.5 text-sm transition-colors"
                    :class="
                        activeTab === tab.value
                            ? 'bg-background text-foreground shadow-xs'
                            : 'text-muted-foreground hover:bg-background/40 hover:text-foreground'
                    "
                    @click="activeTab = tab.value"
                >
                    <component :is="tab.icon" class="size-4" />
                    {{ tab.label }}
                </button>
            </div>

            <!-- ─── Saved Queries tab ──────────────────────────────────── -->
            <section v-show="activeTab === 'queries'" class="flex flex-col gap-4">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-medium">Saved queries</h2>
                        <p class="text-muted-foreground text-xs">
                            Each enabled query is evaluated when a new log row lands. Matches fire one
                            delivery per linked channel (per dedupe window).
                        </p>
                    </div>
                    <Button @click="openQueryDialog()">
                        <Plus class="mr-1 size-4" /> New query
                    </Button>
                </div>

                <div
                    v-if="!savedQueries.length"
                    class="border-border text-muted-foreground rounded-lg border border-dashed p-8 text-center text-sm"
                >
                    No saved queries yet. Create one above to start receiving alerts on log matches.
                </div>

                <div v-else class="grid gap-3 md:grid-cols-2">
                    <Card v-for="query in savedQueries" :key="query.id">
                        <CardHeader class="flex-row items-start justify-between gap-3">
                            <div>
                                <CardTitle class="text-base">
                                    {{ query.name }}
                                    <Badge v-if="!query.enabled" variant="outline" class="ml-2">disabled</Badge>
                                </CardTitle>
                                <CardDescription class="mt-1 flex flex-wrap gap-1 text-xs">
                                    <Badge v-if="query.filter.environment" variant="secondary" class="capitalize">
                                        {{ query.filter.environment }}
                                    </Badge>
                                    <Badge v-if="query.filter.method" variant="outline">{{ query.filter.method }}</Badge>
                                    <Badge v-if="query.filter.status_regex" variant="outline">
                                        status: {{ query.filter.status_regex }}
                                    </Badge>
                                    <Badge v-if="query.filter.action_regex" variant="outline">
                                        action: {{ query.filter.action_regex }}
                                    </Badge>
                                    <Badge v-if="query.filter.subscription_id" variant="outline">
                                        sub: {{ query.filter.subscription_id }}
                                    </Badge>
                                    <span
                                        v-if="!Object.values(query.filter).some((v) => v)"
                                        class="text-muted-foreground"
                                    >
                                        Matches every log row
                                    </span>
                                </CardDescription>
                            </div>
                            <div class="flex shrink-0 items-center gap-1">
                                <Button variant="ghost" size="icon" title="Edit" @click="openQueryDialog(query)">
                                    <Pencil class="size-4" />
                                </Button>
                                <Button variant="ghost" size="icon" title="Delete" @click="deleteQuery(query)">
                                    <Trash2 class="text-destructive size-4" />
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent class="flex flex-col gap-2">
                            <div class="text-muted-foreground text-xs">
                                Routes to {{ query.channels.length }} channel{{ query.channels.length === 1 ? '' : 's' }}
                            </div>
                            <ul v-if="query.channels.length" class="flex flex-wrap gap-2">
                                <li
                                    v-for="link in query.channels"
                                    :key="link.id"
                                    class="bg-muted inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs"
                                >
                                    <component :is="channelKindIcon[link.kind]" class="size-3" />
                                    {{ link.name }}
                                    <span v-if="link.dedupe_window_seconds" class="text-muted-foreground">
                                        · {{ link.dedupe_window_seconds }}s dedupe
                                    </span>
                                </li>
                            </ul>
                        </CardContent>
                    </Card>
                </div>

                <!-- Recent deliveries (per-query rows in the same recent feed) -->
                <Card class="mt-3">
                    <CardHeader>
                        <CardTitle class="text-base">Recent deliveries</CardTitle>
                        <CardDescription>
                            Last 50 across all your saved queries and system alerts.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div
                            v-if="!recentDeliveries.length"
                            class="text-muted-foreground py-6 text-center text-sm"
                        >
                            No deliveries yet.
                        </div>
                        <ul v-else class="divide-border divide-y text-sm">
                            <li
                                v-for="delivery in recentDeliveries"
                                :key="delivery.id"
                                class="grid grid-cols-[auto_1fr_auto] items-center gap-3 py-2"
                            >
                                <Badge :variant="deliveryStatusVariant[delivery.status]" class="gap-1">
                                    <component :is="deliveryStatusIcon[delivery.status]" class="size-3" />
                                    {{ delivery.status }}
                                </Badge>
                                <div class="min-w-0">
                                    <p class="truncate font-medium">{{ delivery.title }}</p>
                                    <p class="text-muted-foreground truncate text-xs">
                                        <template v-if="delivery.channel">
                                            <component
                                                :is="channelKindIcon[delivery.channel.kind]"
                                                class="mr-1 inline size-3 align-text-top"
                                            />
                                            {{ delivery.channel.name }}
                                        </template>
                                        <template v-if="delivery.saved_query"> · query «{{ delivery.saved_query.name }}»</template>
                                        <template v-else> · system alert</template>
                                        <template v-if="delivery.error"> · {{ delivery.error }}</template>
                                    </p>
                                </div>
                                <span class="text-muted-foreground text-xs whitespace-nowrap">
                                    {{ relativeTime(delivery.created_at) }}
                                </span>
                            </li>
                        </ul>
                    </CardContent>
                </Card>
            </section>

            <!-- ─── Channels tab ───────────────────────────────────────── -->
            <section v-show="activeTab === 'channels'" class="flex flex-col gap-4">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-medium">Delivery channels</h2>
                        <p class="text-muted-foreground text-xs">
                            URLs and secrets are encrypted at rest and never returned over the wire.
                        </p>
                    </div>
                    <Button @click="openChannelDialog()">
                        <Plus class="mr-1 size-4" /> New channel
                    </Button>
                </div>

                <div
                    v-if="!channels.length"
                    class="border-border text-muted-foreground rounded-lg border border-dashed p-8 text-center text-sm"
                >
                    No channels yet. Add one above to start receiving alerts.
                </div>

                <div v-else class="grid gap-3 md:grid-cols-2">
                    <Card v-for="channel in channels" :key="channel.id">
                        <CardHeader class="flex-row items-start justify-between gap-3">
                            <div class="flex min-w-0 items-start gap-3">
                                <div class="bg-muted flex size-9 shrink-0 items-center justify-center rounded-md">
                                    <component :is="channelKindIcon[channel.kind]" class="size-5" />
                                </div>
                                <div class="min-w-0">
                                    <CardTitle class="truncate text-base">
                                        {{ channel.name }}
                                        <Badge v-if="!channel.enabled" variant="outline" class="ml-2">disabled</Badge>
                                    </CardTitle>
                                    <CardDescription class="text-xs">
                                        {{ channelKindLabel[channel.kind] }}
                                    </CardDescription>
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-1">
                                <Button variant="ghost" size="icon" title="Edit" @click="openChannelDialog(channel)">
                                    <Pencil class="size-4" />
                                </Button>
                                <Button variant="ghost" size="icon" title="Delete" @click="deleteChannel(channel)">
                                    <Trash2 class="text-destructive size-4" />
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <dl class="text-muted-foreground grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                                <template v-if="channel.config.url">
                                    <dt>URL</dt>
                                    <dd class="text-foreground break-all font-mono">{{ channel.config.url }}</dd>
                                </template>
                                <template v-if="channel.config.to_address">
                                    <dt>Recipient</dt>
                                    <dd class="text-foreground font-mono">{{ channel.config.to_address }}</dd>
                                </template>
                                <template v-if="channel.config.secret">
                                    <dt>Signing secret</dt>
                                    <dd class="text-foreground font-mono">{{ channel.config.secret }}</dd>
                                </template>
                            </dl>
                        </CardContent>
                    </Card>
                </div>
            </section>

            <!-- ─── System alerts tab ──────────────────────────────────── -->
            <section v-show="activeTab === 'system'" class="flex flex-col gap-4">
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <AlertTriangle class="size-5" /> System alerts
                        </CardTitle>
                        <CardDescription>
                            Pick the channels that receive infrastructure-level notifications: a
                            BookingExperts session expiring within 48 hours, three consecutive
                            scrape failures of the same kind, and auto-scraped subscriptions that
                            haven't completed in over 24 hours.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div
                            v-if="!channels.length"
                            class="text-muted-foreground border-border rounded-md border border-dashed p-6 text-center text-sm"
                        >
                            Create at least one channel before opting into system alerts.
                        </div>
                        <ul v-else class="divide-border divide-y">
                            <li
                                v-for="channel in channels"
                                :key="channel.id"
                                class="flex items-center justify-between gap-3 py-2"
                            >
                                <div class="flex min-w-0 items-center gap-2">
                                    <component :is="channelKindIcon[channel.kind]" class="text-muted-foreground size-4" />
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium">{{ channel.name }}</p>
                                        <p class="text-muted-foreground text-xs">{{ channelKindLabel[channel.kind] }}</p>
                                    </div>
                                </div>
                                <Switch
                                    :model-value="systemSelected.includes(channel.id)"
                                    @update:model-value="(on) => toggleSystemChannel(channel.id, !!on)"
                                />
                            </li>
                        </ul>
                        <div class="flex justify-end">
                            <Button :disabled="!systemDirty" @click="saveSystemChannels">
                                Save selection
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </section>
        </div>

        <!-- ─── Saved-query dialog ─────────────────────────────────────── -->
        <Dialog v-model:open="queryDialogOpen">
            <DialogContent class="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{{ queryEditing ? 'Edit saved query' : 'New saved query' }}</DialogTitle>
                    <DialogDescription>
                        Empty filter fields are ignored. Regex fields use PCRE syntax with
                        forward-slash delimiters (e.g. <code>#^5\d\d$#</code> for any 5xx).
                    </DialogDescription>
                </DialogHeader>

                <form class="grid gap-4" @submit.prevent="submitQuery">
                    <div class="grid gap-1.5">
                        <Label for="query-name">Name</Label>
                        <Input id="query-name" v-model="queryForm.name" required maxlength="120" />
                        <p v-if="queryForm.errors.name" class="text-destructive text-xs">{{ queryForm.errors.name }}</p>
                    </div>

                    <div class="flex items-center gap-2">
                        <Switch id="query-enabled" v-model="queryForm.enabled" />
                        <Label for="query-enabled">Enabled</Label>
                    </div>

                    <fieldset class="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <legend class="text-muted-foreground col-span-full text-xs uppercase tracking-wide">
                            Filter expression
                        </legend>

                        <div class="grid gap-1.5">
                            <Label for="filter-subscription">Subscription</Label>
                            <Select v-model="queryForm.filter.subscription_id">
                                <SelectTrigger id="filter-subscription">
                                    <SelectValue placeholder="(any)" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="">(any)</SelectItem>
                                    <SelectItem v-for="sub in subscriptions" :key="sub.id" :value="sub.id">
                                        {{ sub.name }} — {{ sub.environment }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="filter-environment">Environment</Label>
                            <Select v-model="queryForm.filter.environment">
                                <SelectTrigger id="filter-environment">
                                    <SelectValue placeholder="(any)" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="">(any)</SelectItem>
                                    <SelectItem value="production">production</SelectItem>
                                    <SelectItem value="staging">staging</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="filter-method">HTTP method</Label>
                            <Input
                                id="filter-method"
                                v-model="queryForm.filter.method"
                                placeholder="(any)"
                                maxlength="16"
                            />
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="filter-status">Status regex</Label>
                            <Input
                                id="filter-status"
                                v-model="queryForm.filter.status_regex"
                                placeholder="#^5\d\d$#"
                                maxlength="200"
                            />
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="filter-action">Action regex</Label>
                            <Input
                                id="filter-action"
                                v-model="queryForm.filter.action_regex"
                                placeholder="#bookings#i"
                                maxlength="200"
                            />
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="filter-since">Since (ISO 8601)</Label>
                            <Input
                                id="filter-since"
                                v-model="queryForm.filter.since"
                                placeholder="2026-05-01T00:00:00Z"
                                maxlength="64"
                            />
                        </div>
                    </fieldset>

                    <fieldset class="flex flex-col gap-2">
                        <legend class="text-muted-foreground text-xs uppercase tracking-wide">
                            Channels
                        </legend>
                        <p v-if="!channels.length" class="text-muted-foreground text-xs">
                            No channels defined yet. Create one in the Channels tab first.
                        </p>
                        <div v-else class="grid gap-2">
                            <div
                                v-for="channel in channels"
                                :key="channel.id"
                                class="border-border flex items-center justify-between gap-3 rounded-md border p-2"
                            >
                                <label class="flex min-w-0 items-center gap-2">
                                    <Checkbox
                                        :model-value="isChannelOnQuery(channel.id)"
                                        @update:model-value="(on: boolean | string) => toggleQueryChannel(channel.id, !!on)"
                                    />
                                    <component :is="channelKindIcon[channel.kind]" class="text-muted-foreground size-4" />
                                    <span class="truncate text-sm">{{ channel.name }}</span>
                                </label>
                                <div
                                    v-if="isChannelOnQuery(channel.id)"
                                    class="flex shrink-0 items-center gap-1.5 text-xs"
                                >
                                    <Label :for="`dedupe-${channel.id}`" class="text-muted-foreground">
                                        Dedupe (s)
                                    </Label>
                                    <Input
                                        :id="`dedupe-${channel.id}`"
                                        type="number"
                                        min="0"
                                        max="86400"
                                        class="h-8 w-24"
                                        :model-value="queryForm.channels.find((c) => c.id === channel.id)?.dedupe_window_seconds ?? 60"
                                        @update:model-value="(v: string | number) => setChannelDedupe(channel.id, Number(v))"
                                    />
                                </div>
                            </div>
                        </div>
                    </fieldset>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="queryDialogOpen = false">Cancel</Button>
                        <Button type="submit" :disabled="queryForm.processing">
                            {{ queryEditing ? 'Save changes' : 'Create query' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- ─── Channel dialog ─────────────────────────────────────────── -->
        <Dialog v-model:open="channelDialogOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ channelEditing ? 'Edit channel' : 'New channel' }}</DialogTitle>
                    <DialogDescription>
                        {{
                            channelEditing
                                ? 'Leave URL/secret/email blank to keep the existing encrypted value.'
                                : 'Pick a delivery kind and supply the URL or recipient.'
                        }}
                    </DialogDescription>
                </DialogHeader>

                <form class="grid gap-4" @submit.prevent="submitChannel">
                    <div class="grid gap-1.5">
                        <Label for="channel-name">Name</Label>
                        <Input id="channel-name" v-model="channelForm.name" required maxlength="120" />
                        <p v-if="channelForm.errors.name" class="text-destructive text-xs">
                            {{ channelForm.errors.name }}
                        </p>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="channel-kind">Kind</Label>
                        <Select v-model="channelForm.kind">
                            <SelectTrigger id="channel-kind">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="kind in channelKinds" :key="kind" :value="kind">
                                    {{ channelKindLabel[kind] }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div v-if="channelForm.kind === 'slack'" class="grid gap-1.5">
                        <Label for="slack-url">Incoming webhook URL</Label>
                        <Input
                            id="slack-url"
                            v-model="channelForm.config.url"
                            type="url"
                            :placeholder="channelEditing ? '(unchanged)' : 'https://hooks.slack.com/services/...'"
                        />
                    </div>

                    <div v-if="channelForm.kind === 'webhook'" class="flex flex-col gap-3">
                        <div class="grid gap-1.5">
                            <Label for="webhook-url">Webhook URL</Label>
                            <Input
                                id="webhook-url"
                                v-model="channelForm.config.url"
                                type="url"
                                :placeholder="channelEditing ? '(unchanged)' : 'https://your.endpoint/incoming'"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="webhook-secret">Signing secret (optional)</Label>
                            <Input
                                id="webhook-secret"
                                v-model="channelForm.config.secret"
                                :placeholder="channelEditing ? '(unchanged)' : 'shared secret for HMAC-SHA256'"
                            />
                            <p class="text-muted-foreground text-xs">
                                Sent as <code>X-BexLogs-Signature: sha256=&lt;hex&gt;</code>.
                            </p>
                        </div>
                    </div>

                    <div v-if="channelForm.kind === 'email'" class="grid gap-1.5">
                        <Label for="email-to">Recipient email</Label>
                        <Input
                            id="email-to"
                            v-model="channelForm.config.to_address"
                            type="email"
                            :placeholder="channelEditing ? '(unchanged)' : 'ops@example.com'"
                        />
                    </div>

                    <div class="flex items-center gap-2">
                        <Switch id="channel-enabled" v-model="channelForm.enabled" />
                        <Label for="channel-enabled">Enabled</Label>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" @click="channelDialogOpen = false">
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="channelForm.processing">
                            {{ channelEditing ? 'Save changes' : 'Create channel' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
