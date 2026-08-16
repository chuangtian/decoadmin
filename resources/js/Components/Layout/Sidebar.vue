<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { menu } from '../../config/menu';
import type { MenuItem } from '../../config/menu';
import type { SharedProps } from '../../types';
import SidebarGroup from './SidebarGroup.vue';

defineProps<{ open: boolean }>();
const emit = defineEmits<{ close: [] }>();
const page = usePage<SharedProps>();
const navigation = ref<HTMLElement | null>(null);
const expandedGroups = ref<Record<string, boolean>>({});
const scrollStorageKey = 'admin-sidebar-scroll-position';
const expandedStorageKey = 'admin-sidebar-expanded-groups';

const visibleMenu = computed<MenuItem[]>(() => menu.map((group) => ({
    ...group,
    children: group.children?.filter((item) => Boolean(item.permission && page.props.auth.permissions.includes(item.permission))),
})).filter((group) => Boolean(group.children?.length)));
const groupHasActiveRoute = (group: MenuItem) => group.children?.some((child) => child.route && (page.url === child.route || page.url.startsWith(`${child.route}/`))) ?? false;
const toggleGroup = (name: string) => {
    expandedGroups.value[name] = !expandedGroups.value[name];
    localStorage.setItem(expandedStorageKey, JSON.stringify(expandedGroups.value));
};
const rememberScrollPosition = () => {
    if (navigation.value) sessionStorage.setItem(scrollStorageKey, String(navigation.value.scrollTop));
};

onMounted(() => {
    try {
        expandedGroups.value = JSON.parse(localStorage.getItem(expandedStorageKey) ?? '{}');
    } catch {
        expandedGroups.value = {};
    }

    for (const group of visibleMenu.value) {
        if (expandedGroups.value[group.name] === undefined || groupHasActiveRoute(group)) expandedGroups.value[group.name] = true;
    }

    requestAnimationFrame(() => {
        if (navigation.value) navigation.value.scrollTop = Number(sessionStorage.getItem(scrollStorageKey) ?? 0);
    });
});

onBeforeUnmount(rememberScrollPosition);
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-40 bg-slate-950/60 backdrop-blur-sm lg:hidden" @click="emit('close')" />
    <aside class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col border-r border-white/8 bg-[#0b1220] text-white shadow-2xl transition-transform duration-200 lg:translate-x-0 lg:shadow-none" :class="open ? 'translate-x-0' : '-translate-x-full'">
        <div class="flex h-20 items-center gap-3 border-b border-white/8 px-6">
            <div class="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-teal-400 to-emerald-600 shadow-lg shadow-emerald-950/40">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 7h6a4 4 0 0 1 0 8H7V7Z"/><path d="M7 3v18M13 7h4"/></svg>
            </div>
            <div class="min-w-0"><p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-emerald-400">Commerce OS</p><p class="mt-0.5 truncate font-semibold tracking-tight">{{ page.props.appName }}</p></div>
            <button class="ml-auto rounded-lg p-2 text-slate-400 hover:bg-white/8 hover:text-white lg:hidden" aria-label="关闭导航" @click="emit('close')">×</button>
        </div>

        <nav ref="navigation" class="flex-1 space-y-1 overflow-y-auto px-3 py-5" aria-label="后台主导航" @scroll.passive="rememberScrollPosition">
            <SidebarGroup v-for="group in visibleMenu" :key="group.name" :item="group" :expanded="expandedGroups[group.name] ?? false" :current-url="page.url" @toggle="toggleGroup(group.name)" @navigate="emit('close')" />
        </nav>

        <div class="border-t border-white/8 px-6 py-4 text-xs text-slate-500">
            <div class="flex items-center gap-2"><span class="h-2 w-2 rounded-full bg-emerald-400 shadow-[0_0_10px_rgba(52,211,153,.7)]"/><span>平台运行正常</span></div>
        </div>
    </aside>
</template>
