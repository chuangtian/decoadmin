<script setup lang="ts">
import { onMounted, ref } from 'vue';
import ToastContainer from '../Components/Feedback/ToastContainer.vue';
import Breadcrumb from '../Components/Layout/Breadcrumb.vue';
import Header from '../Components/Layout/Header.vue';
import Sidebar from '../Components/Layout/Sidebar.vue';
import type { BreadcrumbItem } from '../types';

withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItem[]; viewport?: boolean }>(), {
    breadcrumbs: () => [],
    viewport: false,
});

const sidebarOpen = ref(false);
const sidebarCollapsed = ref(false);
const sidebarCollapsedStorageKey = 'admin-sidebar-collapsed';

const toggleSidebarCollapsed = () => {
    sidebarCollapsed.value = !sidebarCollapsed.value;
    localStorage.setItem(sidebarCollapsedStorageKey, String(sidebarCollapsed.value));
};

onMounted(() => {
    sidebarCollapsed.value = localStorage.getItem(sidebarCollapsedStorageKey) === 'true';
});
</script>

<template>
    <div class="h-dvh max-w-full overflow-hidden bg-[#f4f7f9] text-slate-900">
        <ToastContainer />
        <Sidebar :open="sidebarOpen" :collapsed="sidebarCollapsed" @close="sidebarOpen = false" @toggle-collapsed="toggleSidebarCollapsed" />
        <div
            class="flex h-dvh min-h-0 w-full min-w-0 max-w-full flex-col transition-[padding] duration-200"
            :class="sidebarCollapsed ? 'lg:pl-20' : 'lg:pl-72'"
        >
            <Header class="shrink-0" @menu="sidebarOpen = true" />
            <main
                class="min-h-0 min-w-0 max-w-full flex-1 px-4 pt-3 pb-4 sm:px-6 sm:pt-4 sm:pb-6 lg:px-8 lg:pt-4 lg:pb-8"
                :class="viewport ? 'flex flex-col overflow-hidden' : 'overflow-y-auto overflow-x-clip overscroll-x-none'"
            >
                <Breadcrumb v-if="breadcrumbs.length" :items="breadcrumbs" class="mb-5 shrink-0" />
                <div v-if="viewport" class="min-h-0 min-w-0 flex-1">
                    <slot />
                </div>
                <slot v-else />
            </main>
        </div>
    </div>
</template>
