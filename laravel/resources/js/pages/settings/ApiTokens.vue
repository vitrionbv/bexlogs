<script setup lang="ts">
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import {
    Check,
    Copy,
    ExternalLink,
    KeyRound,
    Trash2,
    TriangleAlert,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ApiTokenController from '@/actions/App/Http/Controllers/Settings/ApiTokenController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as indexApiTokens } from '@/routes/api-tokens';

type TokenRow = {
    id: number;
    name: string;
    last_used_at: string | null;
    created_at: string;
};

type Props = {
    tokens: TokenRow[];
    /**
     * Plaintext token surfaced exactly once via Laravel's session flash
     * after a successful create. Re-rendering the page (or any other
     * navigation) clears it. We keep a local mirror so the modal stays
     * open until the operator dismisses it.
     */
    plainTextToken: string | null;
    apiBaseUrl: string;
    docsUrl: string;
};

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'API tokens',
                href: indexApiTokens(),
            },
        ],
    },
});

const page = usePage();

// Mirror flashed token so the dialog can stay open across the brief
// post-create re-render (Inertia replays props synchronously).
const justCreatedToken = ref<string | null>(props.plainTextToken);
watch(
    () => props.plainTextToken,
    (next) => {
        if (next) {
            justCreatedToken.value = next;
        }
    },
);

const showCreatedDialog = computed({
    get: () => !!justCreatedToken.value,
    set: (open: boolean) => {
        if (!open) {
            justCreatedToken.value = null;
        }
    },
});

// Confirm-revoke dialog. Keyed by token id; we keep a second copy of
// the name so the modal can render it without holding a reference to
// the row that may already have left the list when the request lands.
const revokeTarget = ref<{ id: number; name: string } | null>(null);
const revokeOpen = computed({
    get: () => revokeTarget.value !== null,
    set: (open: boolean) => {
        if (!open) {
            revokeTarget.value = null;
        }
    },
});
const revoking = ref(false);

function askRevoke(token: TokenRow) {
    revokeTarget.value = { id: token.id, name: token.name };
}

function confirmRevoke() {
    if (!revokeTarget.value) {
        return;
    }

    const target = revokeTarget.value;
    revoking.value = true;

    router.delete(`/settings/api-tokens/${target.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(`Revoked “${target.name}”`);
            revokeTarget.value = null;
        },
        onError: () => {
            toast.error('Could not revoke token.');
        },
        onFinish: () => {
            revoking.value = false;
        },
    });
}

const copyState = ref<Record<string, boolean>>({});

async function copy(value: string, key: string) {
    try {
        await navigator.clipboard.writeText(value);
        copyState.value = { ...copyState.value, [key]: true };
        toast.success('Copied to clipboard');
        setTimeout(() => {
            copyState.value = { ...copyState.value, [key]: false };
        }, 1500);
    } catch {
        toast.error('Copy failed — copy manually.');
    }
}

const curlExample = computed(() => {
    const token = justCreatedToken.value ?? '<YOUR_TOKEN>';

    return `curl -H "Accept: application/ld+json" \\\n     -H "Authorization: Bearer ${token}" \\\n     ${props.apiBaseUrl}/organizations`;
});

function fmtDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString();
}

function relative(iso: string | null): string {
    if (!iso) {
        return 'Never';
    }

    const ms = Date.now() - new Date(iso).getTime();

    if (ms < 60_000) {
        return 'Just now';
    }

    if (ms < 3_600_000) {
        return `${Math.floor(ms / 60_000)}m ago`;
    }

    if (ms < 86_400_000) {
        return `${Math.floor(ms / 3_600_000)}h ago`;
    }

    return `${Math.floor(ms / 86_400_000)}d ago`;
}

const flashedErrors = computed(() => {
    const errors = (page.props as { errors?: Record<string, string> }).errors;

    return errors ?? {};
});
</script>

<template>
    <Head title="API tokens" />

    <h1 class="sr-only">API tokens</h1>

    <div class="space-y-12">
        <section class="space-y-6">
            <Heading
                variant="small"
                title="API access"
                description="Authenticate REST API requests with a Personal Access Token. The base URL and an example curl are below."
            />

            <div class="space-y-3 rounded-lg border bg-muted/40 p-4">
                <div class="space-y-1">
                    <Label class="text-xs text-muted-foreground uppercase"
                        >Base URL</Label
                    >
                    <div class="flex items-center gap-2">
                        <code
                            class="flex-1 truncate rounded bg-background px-3 py-2 font-mono text-sm"
                            data-test="api-base-url"
                            >{{ apiBaseUrl }}</code
                        >
                        <Button
                            type="button"
                            size="icon"
                            variant="outline"
                            aria-label="Copy base URL"
                            @click="copy(apiBaseUrl, 'baseUrl')"
                        >
                            <Check
                                v-if="copyState.baseUrl"
                                class="size-4 text-emerald-600"
                            />
                            <Copy v-else class="size-4" />
                        </Button>
                    </div>
                </div>

                <div class="space-y-1">
                    <Label class="text-xs text-muted-foreground uppercase"
                        >Example request</Label
                    >
                    <div class="flex items-start gap-2">
                        <pre
                            class="flex-1 overflow-x-auto rounded bg-background px-3 py-2 font-mono text-xs leading-relaxed"
                            >{{ curlExample }}</pre
                        >
                        <Button
                            type="button"
                            size="icon"
                            variant="outline"
                            aria-label="Copy example"
                            @click="copy(curlExample, 'curl')"
                        >
                            <Check
                                v-if="copyState.curl"
                                class="size-4 text-emerald-600"
                            />
                            <Copy v-else class="size-4" />
                        </Button>
                    </div>
                    <p
                        v-if="!justCreatedToken"
                        class="text-xs text-muted-foreground"
                    >
                        Replace
                        <code class="font-mono">&lt;YOUR_TOKEN&gt;</code> with
                        the value shown after creating a token.
                    </p>
                </div>

                <div>
                    <Button as-child variant="link" class="px-0">
                        <a
                            :href="docsUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <ExternalLink class="size-4" />
                            Open OpenAPI documentation
                        </a>
                    </Button>
                </div>
            </div>
        </section>

        <section class="space-y-6">
            <Heading
                variant="small"
                title="Create token"
                description="Tokens grant read-only access to your organizations' data. Keep them secret — anyone with the token can read your API."
            />

            <Form
                v-bind="ApiTokenController.store.form()"
                :options="{ preserveScroll: true }"
                reset-on-success
                class="flex flex-col gap-3 sm:flex-row sm:items-end"
                v-slot="{ errors, processing }"
            >
                <div class="grid flex-1 gap-2">
                    <Label for="name">Name</Label>
                    <Input
                        id="name"
                        name="name"
                        type="text"
                        placeholder="e.g. CI deploy script"
                        autocomplete="off"
                        data-test="api-token-name"
                    />
                    <InputError :message="errors.name" />
                </div>
                <Button
                    type="submit"
                    :disabled="processing"
                    data-test="create-api-token-button"
                >
                    <KeyRound class="size-4" />Create token
                </Button>
            </Form>

            <InputError
                v-if="flashedErrors.name && !justCreatedToken"
                :message="flashedErrors.name"
            />
        </section>

        <section class="space-y-4">
            <Heading
                variant="small"
                title="Active tokens"
                description="The plaintext value is shown only once at creation. To rotate a token, revoke it here and create a new one."
            />

            <div
                v-if="!tokens.length"
                class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                No tokens yet. Create one above to start calling the API.
            </div>

            <ul v-else class="space-y-2" data-test="api-token-list">
                <li
                    v-for="token in tokens"
                    :key="token.id"
                    class="flex flex-col gap-2 rounded-lg border p-4 sm:flex-row sm:items-center sm:justify-between"
                    :data-test="`api-token-row-${token.id}`"
                >
                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <span class="font-medium">{{ token.name }}</span>
                            <Badge
                                v-if="!token.last_used_at"
                                variant="outline"
                                class="text-xs"
                                >Unused</Badge
                            >
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Created {{ fmtDate(token.created_at) }} · Last used
                            {{ relative(token.last_used_at) }}
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="text-destructive hover:text-destructive"
                        :data-test="`revoke-api-token-${token.id}`"
                        @click="askRevoke(token)"
                    >
                        <Trash2 class="size-4" />Revoke
                    </Button>
                </li>
            </ul>
        </section>
    </div>

    <!--
        Plaintext-token modal. Sanctum hashes the token on create, so the
        plaintext returned here is the *only* copy the operator will ever
        see. Closing the dialog wipes it from local state and a future
        re-render of this page won't bring it back (the controller only
        flashes it once).
    -->
    <Dialog v-model:open="showCreatedDialog">
        <DialogContent>
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <KeyRound class="size-5 text-emerald-600" />
                    Token created
                </DialogTitle>
                <DialogDescription>
                    Copy this token now — you won't see it again. If you lose
                    it, revoke it and create a new one.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-3">
                <div class="flex items-center gap-2">
                    <code
                        class="flex-1 rounded bg-muted px-3 py-2 font-mono text-sm break-all"
                        data-test="plain-text-token"
                        >{{ justCreatedToken }}</code
                    >
                    <Button
                        type="button"
                        size="icon"
                        variant="outline"
                        aria-label="Copy token"
                        data-test="copy-plain-text-token"
                        @click="
                            justCreatedToken &&
                            copy(justCreatedToken, 'plaintext')
                        "
                    >
                        <Check
                            v-if="copyState.plaintext"
                            class="size-4 text-emerald-600"
                        />
                        <Copy v-else class="size-4" />
                    </Button>
                </div>

                <div
                    class="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900 dark:border-amber-200/20 dark:bg-amber-700/10 dark:text-amber-100"
                >
                    <TriangleAlert class="size-4 shrink-0" />
                    <p>
                        Treat this token like a password. Anyone with it can
                        read every byte of data your account can see.
                    </p>
                </div>
            </div>

            <DialogFooter>
                <DialogClose as-child>
                    <Button>Done</Button>
                </DialogClose>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!--
        Confirm-revoke modal. Skipping a confirmation here would let an
        accidental click silently kill an active production integration —
        the token name is shown in the dialog so the operator knows
        exactly which integration they're about to break.
    -->
    <Dialog v-model:open="revokeOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Revoke token?</DialogTitle>
                <DialogDescription>
                    "{{ revokeTarget?.name }}" will stop working immediately.
                    Any service currently using it will start receiving
                    <code>401 Unauthorized</code> responses.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">Cancel</Button>
                </DialogClose>
                <Button
                    variant="destructive"
                    :disabled="revoking"
                    data-test="confirm-revoke-api-token"
                    @click="confirmRevoke"
                >
                    Revoke
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
