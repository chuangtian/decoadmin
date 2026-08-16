<script setup lang="ts">
import { ref } from 'vue';
import ToastContainer from '../Components/Feedback/ToastContainer.vue';
import Breadcrumb from '../Components/Layout/Breadcrumb.vue';
import Header from '../Components/Layout/Header.vue';
import Sidebar from '../Components/Layout/Sidebar.vue';
import type { BreadcrumbItem } from '../types';

withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItem[] }>(), {
    breadcrumbs: () => [],
});

const sidebarOpen = ref(false);
</script>

<template>
    <div class="min-h-screen bg-[#f4f7f9] text-slate-900">
        <ToastContainer />
        <Sidebar :open="sidebarOpen" @close="sidebarOpen = false" />
        <div class="min-h-screen lg:pl-72">
            <Header @menu="sidebarOpen = true" />
            <main class="p-4 sm:p-6 lg:p-8">
                <Breadcrumb v-if="breadcrumbs.length" :items="breadcrumbs" class="mb-5" />
                <slot />
            </main>
        </div>
    </div>
</template>
