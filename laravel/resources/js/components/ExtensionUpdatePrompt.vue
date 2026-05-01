<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { AlertTriangle, Download } from 'lucide-vue-next';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useExtension } from '@/composables/useExtension';

type InstanceProp = {
    origin: string;
    host: string;
    name: string;
};

type ExtensionShared = {
    latestVersion: string;
    minVersion: string;
    downloadUrl: string;
};

const page = usePage();

const instance = computed<InstanceProp>(
    () =>
        (page.props as any).instance ?? {
            origin: window.location.origin,
            host: window.location.host,
            name: 'BexLogs',
        },
);

const extension = computed<ExtensionShared | null>(
    () => ((page.props as any).extension as ExtensionShared | undefined) ?? null,
);

const { info } = useExtension(instance.value.origin);

/**
 * Compare two dotted numeric version strings.
 * Returns -1 if a < b, 0 if equal, 1 if a > b.
 * Missing segments are treated as 0 ("1.1" is equivalent to "1.1.0").
 * Non-numeric tails (e.g. "1.2.0-beta") are stripped to the leading digits.
 */
function semverCompare(a: string, b: string): number {
    const parse = (v: string) =>
        v
            .split('.')
            .map((seg) => {
                const n = parseInt(seg.replace(/[^0-9].*$/, ''), 10);

                return Number.isFinite(n) ? n : 0;
            });
    const av = parse(a);
    const bv = parse(b);
    const len = Math.max(av.length, bv.length);

    for (let i = 0; i < len; i++) {
        const x = av[i] ?? 0;
        const y = bv[i] ?? 0;

        if (x < y) {
return -1;
}

        if (x > y) {
return 1;
}
    }

    return 0;
}

const semverLt = (a: string, b: string) => semverCompare(a, b) < 0;

const isOutdated = computed(() => {
    if (!extension.value) {
return false;
}

    if (!info.value.detected) {
return false;
}

    if (!info.value.version) {
return false;
}

    return semverLt(info.value.version, extension.value.minVersion);
});

const open = computed({
    get: () => isOutdated.value,
    // The dialog is non-dismissible — `set` is required by `v-model:open`
    // but writing `false` is intentionally a no-op.
    set: () => {},
});

function blockClose(event: Event) {
    event.preventDefault();
}
</script>

<template>
    <Dialog v-if="extension" v-model:open="open">
        <DialogContent
            class="sm:max-w-md"
            :show-close-button="false"
            @pointer-down-outside="blockClose"
            @interact-outside="blockClose"
            @escape-key-down="blockClose"
        >
            <DialogHeader>
                <div class="bg-destructive/15 text-destructive mx-auto flex size-10 items-center justify-center rounded-full">
                    <AlertTriangle class="size-5" />
                </div>
                <DialogTitle class="text-center">Update BexLogs extension</DialogTitle>
                <DialogDescription class="text-center text-base">
                    Hey, you need to update the extension before you can continue.
                </DialogDescription>
            </DialogHeader>

            <div class="flex items-center justify-center gap-2 text-xs">
                <Badge variant="destructive" class="font-mono">
                    Installed: v{{ info.version ?? '?' }}
                </Badge>
                <Badge variant="outline" class="font-mono">
                    Required: v{{ extension.minVersion }}
                </Badge>
            </div>

            <div class="flex flex-col items-center gap-3">
                <Button as-child size="sm">
                    <a :href="extension.downloadUrl" download>
                        <Download class="mr-1 size-4" />
                        Download v{{ extension.latestVersion }}
                    </a>
                </Button>
                <ol class="text-muted-foreground list-decimal space-y-1 self-start pl-5 text-xs">
                    <li>Download the new zip.</li>
                    <li>
                        Open <span class="text-foreground font-mono">chrome://extensions</span>
                        (or <span class="text-foreground font-mono">about:addons</span>).
                    </li>
                    <li>
                        Click <strong>Reload</strong> on BexLogs Authenticator after replacing it.
                    </li>
                </ol>
            </div>
        </DialogContent>
    </Dialog>
</template>
