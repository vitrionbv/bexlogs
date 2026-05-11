<script setup lang="ts">
import { computed } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

/**
 * Small colored-dot badge that summarises a subscription's health
 * score. Hover surfaces the breakdown (success rate, freshness,
 * stability) and the sample size — useful when an operator wants to
 * understand WHY a sub is degraded without clicking through to the
 * full Insights page.
 *
 * The dot itself is intentionally tiny so it can sit inline next to
 * a subscription name in dense list layouts (Manage page) without
 * overwhelming the row.
 */
interface HealthComponents {
    success_rate: number;
    freshness: number;
    stability: number;
}

interface HealthInfo {
    score: number;
    label: 'healthy' | 'degraded' | 'unhealthy';
    components: HealthComponents;
    sample_size: number;
    last_success_at: string | null;
}

const props = defineProps<{
    health: HealthInfo;
}>();

// dot color by label: green for healthy, amber for degraded, red for
// unhealthy. The classes match the rest of the app's status palette
// (Dashboard uses bg-success / bg-warning / bg-destructive too).
const dotClass = computed(() => {
    switch (props.health.label) {
        case 'healthy':
            return 'bg-success';
        case 'degraded':
            return 'bg-warning';
        case 'unhealthy':
            return 'bg-destructive';
        default:
            return 'bg-muted-foreground';
    }
});

function pct(v: number): string {
    return `${Math.round(v * 100)}%`;
}

function formatLastSuccess(iso: string | null): string {
    if (!iso) return 'never';

    const ms = Date.now() - new Date(iso).getTime();

    if (!Number.isFinite(ms) || ms < 0) return 'just now';

    const minutes = Math.floor(ms / 60_000);

    if (minutes < 1) return 'just now';
    if (minutes < 60) return `${minutes}m ago`;

    const hours = Math.floor(minutes / 60);

    if (hours < 24) return `${hours}h ago`;

    const days = Math.floor(hours / 24);

    return `${days}d ago`;
}
</script>

<template>
    <TooltipProvider :delay-duration="100">
        <Tooltip>
            <TooltipTrigger as-child>
                <span
                    class="inline-flex size-2.5 shrink-0 cursor-help rounded-full ring-1 ring-inset ring-black/10"
                    :class="dotClass"
                    :aria-label="`Health: ${health.label} (${pct(health.score)})`"
                />
            </TooltipTrigger>
            <TooltipContent side="top" class="max-w-xs space-y-1 text-xs">
                <p class="font-medium capitalize">
                    {{ health.label }} — {{ pct(health.score) }}
                </p>
                <p>Success rate: {{ pct(health.components.success_rate) }}</p>
                <p>Freshness: {{ pct(health.components.freshness) }}</p>
                <p>Insert stability: {{ pct(health.components.stability) }}</p>
                <p class="text-muted-foreground">
                    Last success: {{ formatLastSuccess(health.last_success_at) }}
                    · {{ health.sample_size }} jobs
                </p>
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
