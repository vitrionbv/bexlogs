<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({ inheritAttrs: false });

defineProps<{ className?: HTMLAttributes['class'] }>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 32 32"
        :class="className"
        v-bind="$attrs"
        aria-hidden="true"
    >
        <!-- Stylised "B" + log-line glyph for BexLogs -->
        <path
            fill="currentColor"
            d="M6 4h11.5a6.5 6.5 0 0 1 4.78 10.91 6.5 6.5 0 0 1-3.78 11.59H6V4Zm5 4v6h6.5a3 3 0 0 0 0-6H11Zm0 10v6h7.5a3 3 0 0 0 0-6H11Z"
        />
        <path
            fill="currentColor"
            opacity="0.55"
            d="M2 8.25a1.25 1.25 0 1 1 2.5 0 1.25 1.25 0 0 1-2.5 0Zm0 7.5a1.25 1.25 0 1 1 2.5 0 1.25 1.25 0 0 1-2.5 0Zm0 7.5a1.25 1.25 0 1 1 2.5 0 1.25 1.25 0 0 1-2.5 0Z"
        />
    </svg>
</template>
