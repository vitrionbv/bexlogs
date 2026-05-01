<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Activity, Cookie, KeyRound, Plug } from 'lucide-vue-next';
import { defineComponent, h } from 'vue';
import type { Component } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

withDefaults(defineProps<{ canRegister: boolean }>(), { canRegister: true });

const FeatureRow = defineComponent({
    name: 'FeatureRow',
    props: {
        icon: { type: Object as () => Component, required: true },
        title: { type: String, required: true },
        description: { type: String, required: true },
    },
    setup(props) {
        return () =>
            h('div', { class: 'flex items-start gap-3' }, [
                h(
                    'div',
                    {
                        class:
                            'bg-accent text-accent-foreground flex size-9 shrink-0 items-center justify-center rounded-lg',
                    },
                    [h(props.icon, { class: 'size-4' })],
                ),
                h('div', { class: 'space-y-0.5' }, [
                    h('p', { class: 'text-sm font-medium' }, props.title),
                    h('p', { class: 'text-muted-foreground text-xs leading-relaxed' }, props.description),
                ]),
            ]);
    },
});
</script>

<template>
    <Head title="BexLogs" />

    <div class="bg-background text-foreground min-h-screen">
        <header class="border-border/60 border-b">
            <div class="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-6 py-4">
                <div class="flex items-center gap-3">
                    <div class="bg-primary text-primary-foreground flex size-9 items-center justify-center rounded-lg shadow-sm">
                        <AppLogoIcon class="size-5" />
                    </div>
                    <span class="text-lg font-semibold tracking-tight">BexLogs</span>
                </div>
                <nav class="flex items-center gap-2 text-sm">
                    <Button v-if="$page.props.auth.user" as-child variant="default">
                        <Link :href="dashboard()">Open dashboard</Link>
                    </Button>
                    <template v-else>
                        <Button as-child variant="ghost">
                            <Link :href="login()">Sign in</Link>
                        </Button>
                        <Button v-if="canRegister" as-child>
                            <Link :href="register()">Create account</Link>
                        </Button>
                    </template>
                </nav>
            </div>
        </header>

        <main class="mx-auto w-full max-w-6xl px-6 py-16">
            <section class="grid items-center gap-10 lg:grid-cols-[1.2fr_1fr]">
                <div class="space-y-6">
                    <p class="text-primary text-xs font-semibold tracking-widest uppercase">
                        Self-hosted log explorer
                    </p>
                    <h1 class="text-4xl leading-tight font-bold tracking-tight md:text-5xl">
                        Watch your BookingExperts integrations.
                        <span class="text-primary">Without a Chrome tab open.</span>
                    </h1>
                    <p class="text-muted-foreground max-w-xl text-base leading-relaxed">
                        BexLogs continuously scrapes the
                        <em>Logboeken bekijken</em> page for the BookingExperts
                        subscriptions you care about, deduplicates entries server-side,
                        and gives you a fast filterable explorer with rich JSON detail.
                    </p>
                    <div class="flex flex-wrap items-center gap-3 pt-2">
                        <Button v-if="$page.props.auth.user" as-child size="lg">
                            <Link :href="dashboard()">Go to dashboard</Link>
                        </Button>
                        <template v-else>
                            <Button as-child size="lg">
                                <Link :href="login()">Sign in to start</Link>
                            </Button>
                            <Button v-if="canRegister" as-child size="lg" variant="outline">
                                <Link :href="register()">Create an account</Link>
                            </Button>
                        </template>
                    </div>
                </div>

                <div class="border-border bg-card relative rounded-2xl border p-6 shadow-sm">
                    <div class="space-y-4">
                        <FeatureRow
                            :icon="Plug"
                            title="One-click extension link"
                            description="The BexLogs browser extension auto-detects this instance and offers to link itself."
                        />
                        <FeatureRow
                            :icon="KeyRound"
                            title="No password leaves your laptop"
                            description="Microsoft SSO is performed in your real browser; cookies are encrypted at rest."
                        />
                        <FeatureRow
                            :icon="Cookie"
                            title="Sessions stay fresh"
                            description="A background validator pings BookingExperts hourly so you always know which sessions are usable."
                        />
                        <FeatureRow
                            :icon="Activity"
                            title="Live job queue"
                            description="See every scrape in flight from the sidebar. Retry, cancel, inspect failures."
                        />
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-border/60 border-t">
            <div class="text-muted-foreground mx-auto flex w-full max-w-6xl items-center justify-between px-6 py-6 text-xs">
                <span>© {{ new Date().getFullYear() }} BexLogs</span>
                <span>Self-hosted · v1.0</span>
            </div>
        </footer>
    </div>
</template>

