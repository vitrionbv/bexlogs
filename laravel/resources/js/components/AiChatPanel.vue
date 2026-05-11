<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ScrollArea } from '@/components/ui/scroll-area';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useChatStream } from '@/composables/useChatStream';

interface SubscriptionMeta {
    id: string;
    name: string | null;
    environment: string | null;
}

const props = defineProps<{
    open: boolean;
    subscription: SubscriptionMeta;
    agentEnabled: boolean;
}>();

const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const conversationId = ref<number | null>(null);
const draft = ref('');
const startError = ref<string | null>(null);
const scrollRoot = ref<HTMLElement | null>(null);

const streamUrl = computed(() =>
    conversationId.value === null
        ? ''
        : `/logs/subscriptions/${props.subscription.id}/chat/${conversationId.value}/stream`,
);

const stream = useChatStream({ streamUrl: () => streamUrl.value });

watch(
    () => props.open,
    async (open) => {
        if (!open || conversationId.value !== null) {
return;
}

        await ensureConversation();
    },
);

watch(
    () => stream.messages.value.length,
    async () => {
        await nextTick();
        scrollRoot.value?.scrollTo({
            top: scrollRoot.value.scrollHeight,
            behavior: 'smooth',
        });
    },
);

async function ensureConversation(): Promise<void> {
    startError.value = null;

    try {
        const response = await fetch(
            `/logs/subscriptions/${props.subscription.id}/chat`,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN':
                        document.head
                            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                            ?.content ?? '',
                },
                body: JSON.stringify({ title: null }),
            },
        );

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const body = (await response.json()) as { conversation: { id: number } };
        conversationId.value = body.conversation.id;
    } catch (err) {
        startError.value =
            err instanceof Error ? err.message : 'Could not start a conversation.';
    }
}

async function submit(): Promise<void> {
    const text = draft.value.trim();

    if (!text || conversationId.value === null) {
return;
}

    draft.value = '';
    await stream.send(text);
}
</script>

<template>
    <Sheet :open="open" @update:open="(v) => emit('update:open', v)">
        <SheetContent side="right" class="flex w-full flex-col gap-4 sm:max-w-xl">
            <SheetHeader>
                <SheetTitle>Ask your logs</SheetTitle>
                <SheetDescription>
                    Scoped to subscription
                    <span class="font-medium text-foreground">
                        {{ subscription.name ?? subscription.id }}
                    </span>
                    <span v-if="subscription.environment">
                        ({{ subscription.environment }})
                    </span>
                </SheetDescription>
            </SheetHeader>

            <div
                v-if="!agentEnabled"
                class="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm"
            >
                The chat agent is disabled (OPENROUTER_API_KEY is not set).
            </div>

            <div
                v-if="startError"
                class="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm"
            >
                {{ startError }}
            </div>

            <ScrollArea class="flex-1 rounded-md border">
                <div ref="scrollRoot" class="space-y-4 p-4">
                    <div
                        v-if="stream.messages.value.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        Ask anything about this subscription's logs. Try
                        <em>"how many 5xx in the last 24h?"</em> or
                        <em>"show the last 10 reservation updates."</em>
                    </div>

                    <div
                        v-for="m in stream.messages.value"
                        :key="m.id"
                        class="flex flex-col gap-1"
                    >
                        <div
                            class="text-xs uppercase tracking-wide text-muted-foreground"
                        >
                            {{ m.role === 'user' ? 'You' : 'Assistant' }}
                            <span v-if="m.pending">· streaming…</span>
                        </div>
                        <div
                            class="whitespace-pre-wrap rounded-md border bg-card p-3 text-sm leading-relaxed"
                            :class="{ 'border-destructive/60': m.error }"
                        >
                            {{ m.content || (m.pending ? '…' : '') }}
                            <div
                                v-if="m.error"
                                class="mt-2 text-xs text-destructive"
                            >
                                {{ m.error }}
                            </div>
                        </div>
                        <details
                            v-if="m.toolCalls.length > 0"
                            class="rounded-md border bg-muted/30 p-2 text-xs"
                        >
                            <summary class="cursor-pointer select-none">
                                {{ m.toolCalls.length }} tool call{{
                                    m.toolCalls.length === 1 ? '' : 's'
                                }}
                            </summary>
                            <ul class="mt-2 space-y-2">
                                <li
                                    v-for="(t, i) in m.toolCalls"
                                    :key="t.id ?? i"
                                    class="rounded border bg-background p-2"
                                >
                                    <div class="font-mono">{{ t.name }}</div>
                                    <pre
                                        class="overflow-x-auto whitespace-pre-wrap text-[11px] text-muted-foreground"
                                    >{{ t.args !== undefined ? JSON.stringify(t.args, null, 2) : '' }}</pre>
                                    <div
                                        v-if="t.summary"
                                        class="mt-1 text-[11px]"
                                    >
                                        →
                                        {{
                                            typeof t.summary === 'string'
                                                ? t.summary
                                                : JSON.stringify(t.summary)
                                        }}
                                    </div>
                                </li>
                            </ul>
                        </details>
                        <div
                            v-if="m.usage"
                            class="text-[11px] text-muted-foreground"
                        >
                            tokens in {{ m.usage.input_tokens ?? 0 }} · out
                            {{ m.usage.output_tokens ?? 0 }}
                        </div>
                    </div>
                </div>
            </ScrollArea>

            <form
                class="flex items-end gap-2"
                @submit.prevent="submit"
            >
                <Input
                    v-model="draft"
                    :disabled="
                        !agentEnabled ||
                        stream.streaming.value ||
                        conversationId === null
                    "
                    placeholder="Ask about this subscription's logs…"
                    class="flex-1"
                />
                <Button
                    type="submit"
                    :disabled="
                        !agentEnabled ||
                        stream.streaming.value ||
                        conversationId === null ||
                        !draft.trim()
                    "
                >
                    Send
                </Button>
                <Button
                    v-if="stream.streaming.value"
                    type="button"
                    variant="outline"
                    @click="stream.cancel()"
                >
                    Stop
                </Button>
            </form>
        </SheetContent>
    </Sheet>
</template>
