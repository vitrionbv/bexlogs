<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Plug } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useExtension } from '@/composables/useExtension';

type InstanceProp = {
    origin: string;
    host: string;
    name: string;
};

const page = usePage();
const instance = computed<InstanceProp>(() => (page.props as any).instance ?? {
    origin: window.location.origin,
    host: window.location.host,
    name: 'BexLogs',
});

const { info, link } = useExtension(instance.value.origin);

const open = ref(false);
const dismissedKey = computed(() => `bexlogs.linkPromptDismissed.${instance.value.origin}`);

onMounted(() => {
    if (sessionStorage.getItem(dismissedKey.value) === '1') {
return;
}
});

watch(
    () => info.value,
    (next) => {
        if (!next.detected) {
return;
}

        if (next.isLinkedHere) {
            open.value = false;

            return;
        }

        // Detected, but linked elsewhere or not linked: prompt unless dismissed.
        if (sessionStorage.getItem(dismissedKey.value) !== '1') {
            open.value = true;
        }
    },
    { deep: true },
);

function confirmLink() {
    link(instance.value.name);
    open.value = false;
}

function dismiss() {
    sessionStorage.setItem(dismissedKey.value, '1');
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <div class="bg-accent text-accent-foreground mx-auto flex size-10 items-center justify-center rounded-full">
                    <Plug class="size-5" />
                </div>
                <DialogTitle class="text-center">BexLogs extension detected</DialogTitle>
                <DialogDescription class="text-center">
                    The browser extension is installed
                    <span v-if="info.version">(v{{ info.version }})</span>.
                    Link it to <span class="text-foreground font-mono text-xs">{{ instance.host }}</span>
                    so the "Authenticate" flow on this instance can capture sessions.
                </DialogDescription>
            </DialogHeader>

            <div v-if="info.linkedOrigin && !info.isLinkedHere" class="bg-muted text-muted-foreground rounded-md p-3 text-xs">
                <p>
                    Currently linked to
                    <span class="text-foreground font-mono">{{ info.linkedOrigin }}</span>.
                </p>
                <p class="mt-1">Linking will replace that.</p>
            </div>

            <DialogFooter class="sm:justify-between">
                <Button variant="ghost" size="sm" @click="dismiss">Not now</Button>
                <Button size="sm" @click="confirmLink">
                    Link to this instance
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
