<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
</script>

<template>
    <div
        class="bg-primary text-primary-foreground flex aspect-square size-9 items-center justify-center rounded-lg shadow-sm ring-1 ring-black/5 dark:ring-white/10"
    >
        <AppLogoIcon class="size-5" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm leading-tight">
        <span class="truncate font-semibold tracking-tight">BexLogs</span>
        <span class="text-muted-foreground truncate text-[11px]">
            BookingExperts log explorer
        </span>
    </div>
</template>
