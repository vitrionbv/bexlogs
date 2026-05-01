<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Check,
    Copy,
    KeyRound,
    Mail,
    Pencil,
    Plus,
    ShieldCheck,
    Trash2,
    UserPlus,
    X,
} from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
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
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

interface UserRow {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    email_verified_at: string | null;
    created_at: string | null;
    updated_at: string | null;
    is_self: boolean;
}

interface Paginator<T> {
    data: T[];
    current_page: number;
    last_page: number;
    links: { url: string | null; label: string; active: boolean }[];
    from: number | null;
    to: number | null;
    total: number;
}

interface FlashData {
    status?: string;
    error?: string;
    generated_password?: string;
    generated_password_for?: string;
}

const props = defineProps<{
    users: Paginator<UserRow>;
    allowRegistration: boolean;
    flash: FlashData;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Users', href: '/admin/users' }] },
});

const page = usePage();
const currentUserId = computed<number | null>(() => {
    const u = (page.props as { auth?: { user?: { id?: number } } }).auth?.user;

    return u?.id ?? null;
});

// ─── Flash: newly-generated password card ────────────────────────────────────

const dismissedPasswordKey = computed(() =>
    props.flash.generated_password
        ? `admin.users.passwordDismissed:${props.flash.generated_password_for ?? ''}:${props.flash.generated_password}`
        : null,
);

const passwordDismissed = ref(false);
watch(
    dismissedPasswordKey,
    (key) => {
        passwordDismissed.value = key
            ? sessionStorage.getItem(key) === '1'
            : false;
    },
    { immediate: true },
);

const showGeneratedPassword = computed(
    () => !!props.flash.generated_password && !passwordDismissed.value,
);

function dismissPassword() {
    if (dismissedPasswordKey.value) {
        sessionStorage.setItem(dismissedPasswordKey.value, '1');
    }

    passwordDismissed.value = true;
}

const passwordCopied = ref(false);
async function copyPassword() {
    if (!props.flash.generated_password) {
return;
}

    try {
        await navigator.clipboard.writeText(props.flash.generated_password);
        passwordCopied.value = true;
        toast.success('Password copied to clipboard');
        setTimeout(() => (passwordCopied.value = false), 2000);
    } catch {
        toast.error('Could not copy — copy manually.');
    }
}

// ─── Create / edit dialog ────────────────────────────────────────────────────

type DialogMode = { kind: 'closed' } | { kind: 'create' } | { kind: 'edit'; user: UserRow };
const dialog = ref<DialogMode>({ kind: 'closed' });

const editingSelf = computed(
    () => dialog.value.kind === 'edit' && dialog.value.user.id === currentUserId.value,
);

const createForm = useForm({
    name: '',
    email: '',
    password: '',
    is_admin: false,
});
const editForm = useForm({
    name: '',
    email: '',
    is_admin: false,
});

function openCreate() {
    createForm.reset();
    createForm.clearErrors();
    dialog.value = { kind: 'create' };
}

function openEdit(user: UserRow) {
    editForm.reset();
    editForm.clearErrors();
    editForm.name = user.name;
    editForm.email = user.email;
    editForm.is_admin = user.is_admin;
    dialog.value = { kind: 'edit', user };
}

function closeDialog() {
    dialog.value = { kind: 'closed' };
}

function submitCreate() {
    createForm.post('/admin/users', {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('User created');
            closeDialog();
        },
    });
}

function submitEdit() {
    if (dialog.value.kind !== 'edit') {
return;
}

    const id = dialog.value.user.id;
    editForm.patch(`/admin/users/${id}`, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('User updated');
            closeDialog();
        },
        onError: (errs) => {
            const first = Object.values(errs)[0];

            if (first) {
toast.error(String(first));
}
        },
    });
}

const dialogOpen = computed({
    get: () => dialog.value.kind !== 'closed',
    set: (v: boolean) => {
        if (!v) {
closeDialog();
}
    },
});

// ─── Confirmation dialog (delete + password reset) ───────────────────────────

type Confirm =
    | { kind: 'closed' }
    | { kind: 'delete'; user: UserRow; processing: boolean }
    | { kind: 'reset'; user: UserRow; processing: boolean };
const confirm = reactive<{ value: Confirm }>({ value: { kind: 'closed' } });

const confirmOpen = computed({
    get: () => confirm.value.kind !== 'closed',
    set: (v: boolean) => {
        if (!v) {
confirm.value = { kind: 'closed' };
}
    },
});

function askDelete(user: UserRow) {
    confirm.value = { kind: 'delete', user, processing: false };
}

function askReset(user: UserRow) {
    confirm.value = { kind: 'reset', user, processing: false };
}

const deleteForm = useForm({});
const resetForm = useForm({});

function performDelete() {
    if (confirm.value.kind !== 'delete') {
return;
}

    const target = confirm.value.user;
    confirm.value.processing = true;
    deleteForm.delete(`/admin/users/${target.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(`Deleted ${target.email}`);
            confirm.value = { kind: 'closed' };
        },
        onError: (errs) => {
            const first = Object.values(errs)[0];
            toast.error(String(first ?? 'Delete failed'));
            confirm.value = { kind: 'closed' };
        },
    });
}

function performReset() {
    if (confirm.value.kind !== 'reset') {
return;
}

    const target = confirm.value.user;
    confirm.value.processing = true;
    resetForm.post(`/admin/users/${target.id}/password-reset`, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(`Password reset link sent to ${target.email}`);
            confirm.value = { kind: 'closed' };
        },
        onError: (errs) => {
            const first = Object.values(errs)[0];
            toast.error(String(first ?? 'Could not send reset link'));
            confirm.value = { kind: 'closed' };
        },
    });
}

// ─── Formatting helpers ──────────────────────────────────────────────────────

function fmtDate(iso: string | null): string {
    if (!iso) {
return '—';
}

    const d = new Date(iso);

    if (Number.isNaN(d.getTime())) {
return iso;
}

    return d.toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function fmtRelative(iso: string | null): string {
    if (!iso) {
return '—';
}

    const t = new Date(iso).getTime();

    if (Number.isNaN(t)) {
return iso;
}

    const diff = Date.now() - t;
    const sec = Math.floor(diff / 1000);

    if (sec < 60) {
return `${sec}s ago`;
}

    const min = Math.floor(sec / 60);

    if (min < 60) {
return `${min}m ago`;
}

    const hr = Math.floor(min / 60);

    if (hr < 24) {
return `${hr}h ago`;
}

    const day = Math.floor(hr / 24);

    return `${day}d ago`;
}
</script>

<template>
    <Head title="Users" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-4 p-4 md:p-6">
        <header class="flex items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Users</h1>
                <p class="text-muted-foreground text-sm">
                    Manage who can sign in to BexLogs and who has admin access.
                </p>
            </div>
            <Button @click="openCreate">
                <Plus class="mr-1 size-4" /> New user
            </Button>
        </header>

        <Alert v-if="allowRegistration" variant="destructive">
            <AlertTriangle class="size-4" />
            <AlertTitle>Public registration is enabled</AlertTitle>
            <AlertDescription>
                Anyone with the URL can sign up at
                <code class="bg-muted rounded px-1 text-xs">/register</code>. In production,
                set <code class="bg-muted rounded px-1 text-xs">ALLOW_REGISTRATION=false</code>
                in your environment file and restart the app.
            </AlertDescription>
        </Alert>

        <Card v-if="showGeneratedPassword" class="border-primary/40 bg-primary/5">
            <CardHeader class="pb-2">
                <CardTitle class="flex items-center gap-2 text-base">
                    <KeyRound class="size-4" />
                    Auto-generated password for {{ flash.generated_password_for }}
                </CardTitle>
                <CardDescription>
                    Copy and share this password securely. It will not be shown again.
                </CardDescription>
            </CardHeader>
            <CardContent class="flex flex-wrap items-center gap-2">
                <code
                    class="bg-background border-border min-w-[16rem] flex-1 truncate rounded-md border px-3 py-2 font-mono text-sm"
                >
                    {{ flash.generated_password }}
                </code>
                <Button variant="outline" @click="copyPassword">
                    <component :is="passwordCopied ? Check : Copy" class="mr-1 size-4" />
                    {{ passwordCopied ? 'Copied' : 'Copy' }}
                </Button>
                <Button variant="ghost" @click="dismissPassword">
                    <X class="mr-1 size-4" /> Dismiss
                </Button>
            </CardContent>
        </Card>

        <Card>
            <CardContent class="p-0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Name</TableHead>
                            <TableHead>Email</TableHead>
                            <TableHead class="w-24">Admin</TableHead>
                            <TableHead class="w-44">Joined</TableHead>
                            <TableHead class="w-44">Last seen</TableHead>
                            <TableHead class="w-44 text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="user in users.data" :key="user.id">
                            <TableCell class="font-medium">
                                <span class="flex items-center gap-2">
                                    {{ user.name }}
                                    <Badge v-if="user.is_self" variant="outline" class="text-[10px]">
                                        you
                                    </Badge>
                                </span>
                            </TableCell>
                            <TableCell class="text-muted-foreground">
                                {{ user.email }}
                            </TableCell>
                            <TableCell>
                                <Badge
                                    v-if="user.is_admin"
                                    variant="default"
                                    class="flex w-fit items-center gap-1"
                                >
                                    <ShieldCheck class="size-3" /> admin
                                </Badge>
                                <span v-else class="text-muted-foreground text-xs">—</span>
                            </TableCell>
                            <TableCell class="text-sm">
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <span class="text-muted-foreground">
                                            {{ fmtRelative(user.created_at) }}
                                        </span>
                                    </TooltipTrigger>
                                    <TooltipContent>{{ fmtDate(user.created_at) }}</TooltipContent>
                                </Tooltip>
                            </TableCell>
                            <TableCell class="text-sm">
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <span class="text-muted-foreground">
                                            {{ fmtRelative(user.updated_at) }}
                                        </span>
                                    </TooltipTrigger>
                                    <TooltipContent>{{ fmtDate(user.updated_at) }}</TooltipContent>
                                </Tooltip>
                            </TableCell>
                            <TableCell>
                                <div class="flex items-center justify-end gap-0.5">
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                class="size-8"
                                                @click="openEdit(user)"
                                            >
                                                <Pencil class="size-4" />
                                            </Button>
                                        </TooltipTrigger>
                                        <TooltipContent>Edit</TooltipContent>
                                    </Tooltip>
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                class="size-8"
                                                @click="askReset(user)"
                                            >
                                                <Mail class="size-4" />
                                            </Button>
                                        </TooltipTrigger>
                                        <TooltipContent>Send password reset</TooltipContent>
                                    </Tooltip>
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                class="text-destructive size-8 disabled:opacity-30"
                                                :disabled="user.is_self"
                                                @click="askDelete(user)"
                                            >
                                                <Trash2 class="size-4" />
                                            </Button>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            {{ user.is_self ? "Can't delete yourself" : 'Delete' }}
                                        </TooltipContent>
                                    </Tooltip>
                                </div>
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="!users.data.length">
                            <TableCell colspan="6" class="text-muted-foreground text-center text-sm">
                                No users yet.
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>

            <div
                v-if="users.last_page > 1"
                class="border-border text-muted-foreground flex items-center justify-between border-t p-3 text-xs"
            >
                <span>
                    Showing {{ users.from ?? 0 }}–{{ users.to ?? 0 }} of {{ users.total }}
                </span>
                <div class="flex items-center gap-1">
                    <Button
                        v-for="link in users.links"
                        :key="link.label"
                        size="sm"
                        :variant="link.active ? 'default' : 'ghost'"
                        :disabled="!link.url"
                        as-child
                    >
                        <a v-if="link.url" :href="link.url" v-html="link.label" />
                        <span v-else v-html="link.label" />
                    </Button>
                </div>
            </div>
        </Card>
    </div>

    <!-- Create / Edit dialog ─────────────────────────────────────────────── -->
    <Dialog v-model:open="dialogOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <UserPlus v-if="dialog.kind === 'create'" class="size-4" />
                    <Pencil v-else class="size-4" />
                    {{ dialog.kind === 'create' ? 'New user' : 'Edit user' }}
                </DialogTitle>
                <DialogDescription v-if="dialog.kind === 'create'">
                    Create an account. Leave the password blank to auto-generate one — it
                    will be shown once after saving.
                </DialogDescription>
                <DialogDescription v-else>
                    Update profile details and admin access.
                </DialogDescription>
            </DialogHeader>

            <!-- CREATE FORM -->
            <form
                v-if="dialog.kind === 'create'"
                class="space-y-3"
                @submit.prevent="submitCreate"
            >
                <div class="space-y-1">
                    <Label for="create-name">Name</Label>
                    <Input id="create-name" v-model="createForm.name" autofocus />
                    <p v-if="createForm.errors.name" class="text-destructive text-xs">
                        {{ createForm.errors.name }}
                    </p>
                </div>
                <div class="space-y-1">
                    <Label for="create-email">Email</Label>
                    <Input id="create-email" type="email" v-model="createForm.email" />
                    <p v-if="createForm.errors.email" class="text-destructive text-xs">
                        {{ createForm.errors.email }}
                    </p>
                </div>
                <div class="space-y-1">
                    <Label for="create-password">Password (optional)</Label>
                    <Input
                        id="create-password"
                        type="password"
                        v-model="createForm.password"
                        placeholder="Leave blank to auto-generate"
                        autocomplete="new-password"
                    />
                    <p v-if="createForm.errors.password" class="text-destructive text-xs">
                        {{ createForm.errors.password }}
                    </p>
                </div>
                <label class="flex items-center justify-between gap-3 rounded-md border p-3">
                    <span class="flex items-center gap-2 text-sm">
                        <ShieldCheck class="size-4" /> Admin access
                    </span>
                    <Switch v-model="createForm.is_admin" />
                </label>

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="closeDialog">Cancel</Button>
                    <Button type="submit" :disabled="createForm.processing">
                        Create user
                    </Button>
                </DialogFooter>
            </form>

            <!-- EDIT FORM -->
            <form v-else-if="dialog.kind === 'edit'" class="space-y-3" @submit.prevent="submitEdit">
                <div class="space-y-1">
                    <Label for="edit-name">Name</Label>
                    <Input id="edit-name" v-model="editForm.name" autofocus />
                    <p v-if="editForm.errors.name" class="text-destructive text-xs">
                        {{ editForm.errors.name }}
                    </p>
                </div>
                <div class="space-y-1">
                    <Label for="edit-email">Email</Label>
                    <Input id="edit-email" type="email" v-model="editForm.email" />
                    <p v-if="editForm.errors.email" class="text-destructive text-xs">
                        {{ editForm.errors.email }}
                    </p>
                </div>
                <TooltipProvider :delay-duration="150">
                    <Tooltip :disabled="!editingSelf">
                        <TooltipTrigger as-child>
                            <label
                                class="flex items-center justify-between gap-3 rounded-md border p-3"
                                :class="editingSelf && 'opacity-60'"
                            >
                                <span class="flex items-center gap-2 text-sm">
                                    <ShieldCheck class="size-4" /> Admin access
                                </span>
                                <Switch v-model="editForm.is_admin" :disabled="editingSelf" />
                            </label>
                        </TooltipTrigger>
                        <TooltipContent>
                            You can't change your own admin status.
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
                <p v-if="editForm.errors.is_admin" class="text-destructive text-xs">
                    {{ editForm.errors.is_admin }}
                </p>

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="closeDialog">Cancel</Button>
                    <Button type="submit" :disabled="editForm.processing">
                        Save changes
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <!-- Confirm dialog (delete + password reset) ──────────────────────────── -->
    <Dialog v-model:open="confirmOpen">
        <DialogContent class="sm:max-w-md">
            <template v-if="confirm.value.kind === 'delete'">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <Trash2 class="text-destructive size-4" /> Delete user?
                    </DialogTitle>
                    <DialogDescription>
                        Permanently delete <strong>{{ confirm.value.user.email }}</strong> and
                        all their organizations, sessions, and saved filters. This cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="confirmOpen = false">Cancel</Button>
                    <Button
                        variant="destructive"
                        :disabled="confirm.value.processing"
                        @click="performDelete"
                    >
                        Delete user
                    </Button>
                </DialogFooter>
            </template>
            <template v-else-if="confirm.value.kind === 'reset'">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <Mail class="size-4" /> Send password reset?
                    </DialogTitle>
                    <DialogDescription>
                        Email a password-reset link to
                        <strong>{{ confirm.value.user.email }}</strong>. The link expires
                        according to your auth config (default 60 minutes).
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="ghost" @click="confirmOpen = false">Cancel</Button>
                    <Button
                        :disabled="confirm.value.processing"
                        @click="performReset"
                    >
                        Send reset link
                    </Button>
                </DialogFooter>
            </template>
        </DialogContent>
    </Dialog>
</template>
