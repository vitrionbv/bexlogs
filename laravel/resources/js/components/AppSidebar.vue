<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    KeyRound,
    ScrollText,
    Settings2,
    Users,
} from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import SidebarJobs from '@/components/SidebarJobs.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarSeparator,
} from '@/components/ui/sidebar';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    { title: 'Logs', href: '/logs', icon: ScrollText },
    { title: 'Jobs', href: '/jobs', icon: Activity },
    { title: 'Sessions', href: '/authenticate', icon: KeyRound },
    { title: 'Manage', href: '/manage', icon: Settings2 },
];

const adminNavItems: NavItem[] = [
    { title: 'Users', href: '/admin/users', icon: Users },
];

const page = usePage();
const isAdmin = computed<boolean>(() => {
    const auth = (page.props as { auth?: { user?: { is_admin?: boolean } | null } }).auth;

    return !!auth?.user?.is_admin;
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link href="/logs">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
            <SidebarSeparator />
            <SidebarJobs />
            <template v-if="isAdmin">
                <SidebarSeparator />
                <NavMain :items="adminNavItems" label="Admin" />
            </template>
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
