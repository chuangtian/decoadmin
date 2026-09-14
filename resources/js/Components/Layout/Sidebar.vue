<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { menu } from '../../config/menu';
import type { MenuItem } from '../../config/menu';
import type { SharedProps } from '../../types';
import SidebarGroup from './SidebarGroup.vue';

const props = defineProps<{ open: boolean; collapsed: boolean }>();
const emit = defineEmits<{ close: []; toggleCollapsed: [] }>();
const page = usePage<SharedProps>();
const navigation = ref<HTMLElement | null>(null);
const openGroup = ref<string | null>(null);
const scrollStorageKey = 'admin-sidebar-scroll-position';
const openGroupStorageKey = 'sidebar_open_group';
// 这些菜单项的真实路径带组织与店铺作用域，菜单里只登记后缀，进入时按当前上下文补全。
const storeScopedRoutes = ['/student-discounts', '/instagram-feed', '/community-reviews', '/deco-reviews', '/affiliate'];
const isStoreScopedRoute = (route: string) => storeScopedRoutes.some(prefix => route === prefix || route.startsWith(`${prefix}/`));
const contextualRoute = (route?: string) => {
    if (!route || !isStoreScopedRoute(route)) return route;
    const organization = page.props.currentOrganization;
    const store = page.props.currentStore;
    return organization && store
        ? `/organizations/${organization.id}/stores/${store.id}${route}`
        : route;
};

const applicationChildren = computed<MenuItem[]>(() => page.props.applicationNavigation.map((application) => ({
    name: application.name,
    route: application.route,
    icon: 'apps',
    permission: application.permission,
})));

// 同一个应用可能既有固定入口又有安装记录，按最终路由保留先出现的那条。
const dedupeByRoute = (items: MenuItem[]) => {
    const seen = new Set<string>();

    return items.filter((item) => {
        if (!item.route || seen.has(item.route)) return !item.route;
        seen.add(item.route);

        return true;
    });
};

const visibleMenu = computed<MenuItem[]>(() => menu
    .filter((item) => !item.hidden)
    .filter((item) => !item.requiresStore || Boolean(page.props.currentStore))
    .filter((item) => !item.requiresInstalledApp || page.props.applicationAvailability[item.requiresInstalledApp])
    .map((item) => {
        const isApplications = item.dynamicChildren === 'applications';
        const children = isApplications
            ? [...(item.children ?? []), ...applicationChildren.value]
            : item.children;
        const resolved = children
            ?.filter((child) => Boolean(child.permission && page.props.auth.permissions.includes(child.permission)))
            .filter((child) => !child.requiresStore || Boolean(page.props.currentStore))
            .map((child) => ({ ...child, route: contextualRoute(child.route) }))
            // 还没选店铺时店铺级路由补不出完整路径，先隐藏，避免点进去 404。
            .filter((child) => !(child.route && isStoreScopedRoute(child.route)));

        return {
            ...item,
            route: contextualRoute(item.route),
            children: isApplications && resolved
                // 码点序，跟后端 ApplicationCenterNavigationService 的排序保持一致。
                ? dedupeByRoute(resolved).sort((a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : 0))
                : resolved,
        };
    })
    .filter((item) => item.route
        ? Boolean(item.permission && page.props.auth.permissions.includes(item.permission))
        : Boolean(item.children?.length)));
const groupHasActiveRoute = (group: MenuItem) => group.children?.some((child) => child.route && (page.url === child.route || page.url.startsWith(`${child.route}/`))) ?? false;
const toggleGroup = (name: string) => {
    openGroup.value = openGroup.value === name ? null : name;

    if (openGroup.value) {
        localStorage.setItem(openGroupStorageKey, openGroup.value);
    } else {
        localStorage.removeItem(openGroupStorageKey);
    }
};
const handleGroupToggle = (name: string) => {
    if (props.collapsed) {
        openGroup.value = name;
        localStorage.setItem(openGroupStorageKey, name);
        emit('toggleCollapsed');
        return;
    }

    toggleGroup(name);
};
const rememberScrollPosition = () => {
    if (navigation.value) sessionStorage.setItem(scrollStorageKey, String(navigation.value.scrollTop));
};

onMounted(() => {
    const storedGroup = localStorage.getItem(openGroupStorageKey);
    const storedGroupIsVisible = visibleMenu.value.some((group) => group.name === storedGroup);
    const activeGroup = visibleMenu.value.find(groupHasActiveRoute)?.name ?? null;
    openGroup.value = activeGroup ?? (storedGroupIsVisible ? storedGroup : null);

    if (activeGroup) localStorage.setItem(openGroupStorageKey, activeGroup);

    requestAnimationFrame(() => {
        if (navigation.value) navigation.value.scrollTop = Number(sessionStorage.getItem(scrollStorageKey) ?? 0);
    });
});

onBeforeUnmount(rememberScrollPosition);
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-40 bg-slate-950/60 backdrop-blur-sm lg:hidden" @click="emit('close')" />
    <aside
        class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col border-r border-white/8 bg-[#0b1220] text-white shadow-2xl transition-[width,transform] duration-200 lg:translate-x-0 lg:shadow-none"
        :class="[open ? 'translate-x-0' : '-translate-x-full', collapsed ? 'lg:w-20' : 'lg:w-72']"
    >
        <button
            type="button"
            class="absolute top-10 -right-3 z-10 hidden h-7 w-7 -translate-y-1/2 place-items-center rounded-full border border-slate-200 bg-white text-slate-500 shadow-md transition hover:text-slate-900 lg:grid"
            :aria-label="collapsed ? '展开侧边栏' : '收起侧边栏'"
            :title="collapsed ? '展开侧边栏' : '收起侧边栏'"
            @click="emit('toggleCollapsed')"
        >
            <svg class="h-4 w-4 transition-transform duration-200" :class="collapsed ? '' : 'rotate-180'" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 0 1-.02 1.06L8.832 10l3.938 3.71a.75.75 0 1 1-1.04 1.08l-4.5-4.25a.75.75 0 0 1 0-1.08l4.5-4.25a.75.75 0 0 1 1.06.02Z" clip-rule="evenodd" /></svg>
        </button>

        <div class="flex h-20 items-center gap-3 border-b border-white/8 px-6" :class="collapsed ? 'lg:justify-center lg:px-3' : ''">
            <div class="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-teal-400 to-emerald-600 shadow-lg shadow-emerald-950/40">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 7h6a4 4 0 0 1 0 8H7V7Z"/><path d="M7 3v18M13 7h4"/></svg>
            </div>
            <div class="min-w-0" :class="collapsed ? 'lg:hidden' : ''"><p class="text-xs font-semibold tracking-[0.22em] text-emerald-400">电商运营系统</p><p class="mt-0.5 truncate font-semibold tracking-tight">{{ page.props.appName }}</p></div>
            <button class="ml-auto rounded-lg p-2 text-slate-400 hover:bg-white/8 hover:text-white lg:hidden" aria-label="关闭导航" @click="emit('close')">×</button>
        </div>

        <nav ref="navigation" class="flex-1 space-y-1 overflow-y-auto px-3 py-5 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" aria-label="后台主导航" @scroll.passive="rememberScrollPosition">
            <template v-for="group in visibleMenu" :key="group.name">
                <div
                    v-if="group.section"
                    class="px-3 pt-2 pb-2 text-xs font-semibold tracking-[0.2em] text-slate-600"
                    :class="[
                        group.sectionDivider ? 'mt-4 border-t border-white/10 pt-5' : '',
                        collapsed ? 'lg:px-2' : '',
                    ]"
                >
                    <span :class="collapsed ? 'lg:hidden' : ''">{{ group.section }}</span>
                    <span v-if="group.sectionDivider" class="hidden h-px bg-white/10" :class="collapsed ? 'lg:block' : ''" />
                </div>
                <SidebarGroup :item="group" :expanded="openGroup === group.name" :collapsed="collapsed" :current-url="page.url" @toggle="handleGroupToggle(group.name)" @navigate="emit('close')" />
            </template>
        </nav>

        <div class="border-t border-white/8 px-6 py-4 text-xs text-slate-500" :class="collapsed ? 'lg:px-0' : ''">
            <div class="flex items-center gap-2" :class="collapsed ? 'lg:justify-center' : ''"><span class="h-2 w-2 rounded-full bg-emerald-400 shadow-[0_0_10px_rgba(52,211,153,.7)]"/><span :class="collapsed ? 'lg:hidden' : ''">平台运行正常</span></div>
        </div>
    </aside>
</template>
