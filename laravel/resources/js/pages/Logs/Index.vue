<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, Plus } from 'lucide-vue-next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useUserChannel } from '@/composables/useRealtime';

interface PageRow {
    id: number;
    organization: { id: string; name: string };
    application: { id: string; name: string };
    subscription: { id: string; name: string };
    log_count: number;
    last_log_at: string | null;
    environment: 'production' | 'staging';
    auto_scrape: boolean;
}

defineProps<{
    pages: PageRow[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Logs', href: '/logs' }] },
});

useUserChannel({
    'log-batch-inserted': () => router.reload({ only: ['pages'] }),
});

function formatRelative(iso: string | null): string {
    if (!iso) {
return 'never';
}

    const then = new Date(iso).getTime();

    if (Number.isNaN(then)) {
return iso;
}

    const diff = Date.now() - then;
    const sec = Math.floor(diff / 1000);

    if (sec < 60) {
return `${sec}s ago`;
}

    const min = Math.floor(sec / 60);

    if (min < 60) {
return `${min}m ago`;
}

    const hr = Math.floor(min / 60);

    if (hr < 24) {
return `${hr}h ago`;
}

    const day = Math.floor(hr / 24);

    return `${day}d ago`;
}
</script>

<template>
    <Head title="Logs" />

    <div class="mx-auto flex w-full max-w-6xl flex-col gap-4 p-4 md:p-6">
        <header class="flex items-start justify-between gap-3">
            <div class="flex flex-col">
                <h1 class="text-2xl font-semibold tracking-tight">Logs</h1>
                <p class="text-muted-foreground text-sm">
                    Each row is the log feed for one BookingExperts subscription.
                </p>
            </div>
            <Button as-child>
                <Link href="/manage">
                    <Plus class="size-4" /> Add subscription
                </Link>
            </Button>
        </header>

        <Card v-if="!pages.length">
            <CardHeader>
                <CardTitle>No logs yet</CardTitle>
                <CardDescription>
                    Add a BookingExperts subscription on the Manage page, then trigger your first
                    scrape to populate this view.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Button as-child>
                    <Link href="/manage">Go to Manage</Link>
                </Button>
            </CardContent>
        </Card>

        <Card v-else>
            <CardContent class="p-0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Subscription</TableHead>
                            <TableHead>Organization</TableHead>
                            <TableHead>Application</TableHead>
                            <TableHead>Env</TableHead>
                            <TableHead class="text-right">Logs</TableHead>
                            <TableHead>Last log</TableHead>
                            <TableHead class="w-12"></TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="row in pages" :key="row.id">
                            <TableCell class="font-medium">
                                <Link :href="`/logs/${row.id}`" class="hover:underline">
                                    {{ row.subscription.name }}
                                </Link>
                            </TableCell>
                            <TableCell>{{ row.organization.name }}</TableCell>
                            <TableCell>{{ row.application.name }}</TableCell>
                            <TableCell>
                                <Badge :variant="row.environment === 'production' ? 'default' : 'secondary'">
                                    {{ row.environment }}
                                </Badge>
                            </TableCell>
                            <TableCell class="text-right tabular-nums">
                                {{ row.log_count.toLocaleString() }}
                            </TableCell>
                            <TableCell class="text-muted-foreground text-sm">
                                {{ formatRelative(row.last_log_at) }}
                            </TableCell>
                            <TableCell>
                                <Button variant="ghost" size="icon" as-child>
                                    <Link :href="`/logs/${row.id}`">
                                        <ArrowRight class="size-4" />
                                    </Link>
                                </Button>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    </div>
</template>
