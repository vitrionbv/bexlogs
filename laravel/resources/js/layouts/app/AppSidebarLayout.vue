<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import CommandPalette from '@/components/CommandPalette.vue';
import ExtensionLinkPrompt from '@/components/ExtensionLinkPrompt.vue';
import ExtensionUpdatePrompt from '@/components/ExtensionUpdatePrompt.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="overflow-x-hidden">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <slot />
        </AppContent>
        <Toaster />
        <ExtensionUpdatePrompt />
        <ExtensionLinkPrompt />
        <!--
            Cmd-K palette (F16). Always-mounted; listens globally for
            the ⌘K / Ctrl+K binding. Internal `usePage`-driven guard
            makes it a no-op for guest visits even though this layout
            isn't reached unauthenticated.
        -->
        <CommandPalette />
    </AppShell>
</template>
