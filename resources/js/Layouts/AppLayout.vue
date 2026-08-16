<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import Header from '../Components/Layout/Header.vue';
import Sidebar from '../Components/Layout/Sidebar.vue';
import type { SharedProps } from '../types';

const page = usePage<SharedProps>();
const sidebarOpen = ref(false);
</script>

<template>
    <div class="min-h-screen bg-[#f4f7f9] text-slate-900">
        <Sidebar :open="sidebarOpen" @close="sidebarOpen = false" />
        <div class="min-h-screen lg:pl-72">
            <Header @menu="sidebarOpen = true" />
            <main class="p-4 sm:p-6 lg:p-8">
                <div v-if="page.props.flash.success" class="mb-6 flex items-center gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 shadow-sm">
                    <span class="grid h-6 w-6 place-items-center rounded-full bg-emerald-500 text-xs text-white">✓</span>{{ page.props.flash.success }}
                </div>
                <div v-if="page.props.flash.error" class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-800 shadow-sm">{{ page.props.flash.error }}</div>
                <slot />
            </main>
        </div>
    </div>
</template>
