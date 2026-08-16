<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { menu } from '../config/menu';
import type { SharedProps } from '../types';

const page = usePage<SharedProps>();
const visibleMenu = computed(() => menu.filter((item) => !item.permission || page.props.auth.permissions.includes(item.permission)));
const isActive = (href: string) => page.url === href || page.url.startsWith(`${href}/`);
</script>

<template>
    <div class="min-h-screen bg-slate-100 text-slate-900">
        <aside class="fixed inset-y-0 left-0 hidden w-64 border-r border-slate-800 bg-slate-950 text-white lg:block">
            <div class="border-b border-slate-800 px-6 py-6">
                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-emerald-400">Commerce Hub</p>
                <h1 class="mt-2 text-lg font-semibold">{{ page.props.currentOrganization?.name ?? page.props.appName }}</h1>
            </div>
            <nav class="space-y-1 p-3">
                <Link
                    v-for="item in visibleMenu"
                    :key="item.href"
                    :href="item.href"
                    class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm transition"
                    :class="isActive(item.href) ? 'bg-emerald-500/15 text-emerald-300' : 'text-slate-300 hover:bg-slate-900 hover:text-white'"
                >
                    <span class="grid h-8 w-8 place-items-center rounded-md bg-slate-800 text-[10px] font-bold tracking-wide">{{ item.icon }}</span>
                    {{ item.label }}
                </Link>
            </nav>
        </aside>

        <div class="lg:pl-64">
            <header class="sticky top-0 z-10 flex h-16 items-center justify-between border-b border-slate-200 bg-white/90 px-5 backdrop-blur lg:px-8">
                <div>
                    <p class="text-xs text-slate-500">Organization workspace</p>
                    <p class="text-sm font-semibold">{{ page.props.currentOrganization?.name ?? 'No organization' }}</p>
                </div>
                <div class="text-right">
                    <p class="text-sm font-medium">{{ page.props.auth.user?.name }}</p>
                    <p class="text-xs text-slate-500">{{ page.props.auth.user?.email }}</p>
                </div>
            </header>

            <main class="p-5 lg:p-8">
                <div v-if="page.props.flash.success" class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ page.props.flash.success }}
                </div>
                <div v-if="page.props.flash.error" class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    {{ page.props.flash.error }}
                </div>
                <slot />
            </main>
        </div>
    </div>
</template>
