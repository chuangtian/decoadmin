<script setup lang="ts">
import { onMounted, ref } from 'vue';
import ToastContainer from '../Components/Feedback/ToastContainer.vue';
import Breadcrumb from '../Components/Layout/Breadcrumb.vue';
import Header from '../Components/Layout/Header.vue';
import Sidebar from '../Components/Layout/Sidebar.vue';
import type { BreadcrumbItem } from '../types';

withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItem[] }>(), {
    breadcrumbs: () => [],
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
    <div class="min-h-screen bg-[#f4f7f9] text-slate-900">
        <ToastContainer />
        <Sidebar :open="sidebarOpen" :collapsed="sidebarCollapsed" @close="sidebarOpen = false" @toggle-collapsed="toggleSidebarCollapsed" />
        <div class="min-h-screen transition-[padding] duration-200" :class="sidebarCollapsed ? 'lg:pl-20' : 'lg:pl-72'">
            <Header @menu="sidebarOpen = true" />
            <main class="px-4 pt-3 pb-4 sm:px-6 sm:pt-4 sm:pb-6 lg:px-8 lg:pt-4 lg:pb-8">
                <Breadcrumb v-if="breadcrumbs.length" :items="breadcrumbs" class="mb-5" />
                <slot />
            </main>
        </div>
    </div>
</template>
