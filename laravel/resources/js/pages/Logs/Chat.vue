<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AiChatPanel from '@/components/AiChatPanel.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';

interface Conversation {
    id: number;
    title: string | null;
    model: string | null;
    created_at: string;
    updated_at: string;
}

interface SubscriptionMeta {
    id: string;
    name: string | null;
    environment: string | null;
}

const props = defineProps<{
    subscription: SubscriptionMeta;
    conversations: Conversation[];
    agentEnabled: boolean;
}>();

const panelOpen = ref(true);
const breadcrumbs = computed(() => [
    { title: 'Logs', href: '/logs' },
    {
        title: props.subscription.name ?? props.subscription.id,
        href: `/logs/subscriptions/${props.subscription.id}/chat`,
    },
    { title: 'Chat', href: `/logs/subscriptions/${props.subscription.id}/chat` },
]);

function destroy(id: number): void {
    router.delete(`/logs/subscriptions/${props.subscription.id}/chat/${id}`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="`Chat — ${subscription.name ?? subscription.id}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">
                        Ask your logs
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        Subscription
                        <span class="font-medium">
                            {{ subscription.name ?? subscription.id }}
                        </span>
                        <Badge v-if="subscription.environment" variant="outline" class="ml-2">
                            {{ subscription.environment }}
                        </Badge>
                    </p>
                </div>
                <Button @click="panelOpen = true">Open chat</Button>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Recent conversations</CardTitle>
                </CardHeader>
                <CardContent>
                    <div
                        v-if="conversations.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No conversations yet. Open the chat panel to start one.
                    </div>
                    <ul v-else class="divide-y divide-border">
                        <li
                            v-for="c in conversations"
                            :key="c.id"
                            class="flex items-center justify-between gap-3 py-3"
                        >
                            <div class="min-w-0">
                                <div class="truncate font-medium">
                                    {{ c.title ?? `Conversation #${c.id}` }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    {{ c.model ?? 'default model' }} · last updated
                                    {{ new Date(c.updated_at).toLocaleString() }}
                                </div>
                            </div>
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="destroy(c.id)"
                            >
                                Delete
                            </Button>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>

        <AiChatPanel
            :open="panelOpen"
            :subscription="subscription"
            :agent-enabled="agentEnabled"
            @update:open="(v) => (panelOpen = v)"
        />
    </AppLayout>
</template>
