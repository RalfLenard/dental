<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';

import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';

import {
    FileText,
    Stethoscope,
    ClipboardCheck,
    HandHeart,
    BriefcaseMedical,
    Brain,
} from 'lucide-vue-next';

import AppLogo from './AppLogo.vue';

// Navigation items
const mainNavItems = [
    {
        title: 'PDF Dental Records',
        href: '/pdf-records',
        url: '/pdf-records',
        icon: FileText,
    },
   {
        title: 'Pdf Mental Records',
        href: '/mental-pdf-records',
        url: '/mental-pdf-records',
        icon: Brain,
    },
   
] as NavItem[];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('pdf-records.index')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>
    </Sidebar>
</template>