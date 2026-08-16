<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { menu } from '../../config/menu';
import type { SharedProps } from '../../types';
import AppIcon from './AppIcon.vue';

defineProps<{ open: boolean }>();
const emit = defineEmits<{ close: [] }>();
const page = usePage<SharedProps>();
const sections = computed(() => menu.map((section) => ({
    ...section,
    items: section.items.filter((item) => !item.permission || page.props.auth.permissions.includes(item.permission)),
})).filter((section) => section.items.length));
const isActive = (route: string) => page.url === route || page.url.startsWith(`${route}/`);
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-40 bg-slate-950/60 backdrop-blur-sm lg:hidden" @click="emit('close')" />
    <aside class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col border-r border-white/8 bg-[#0b1220] text-white transition-transform duration-200 lg:translate-x-0" :class="open ? 'translate-x-0' : '-translate-x-full'">
        <div class="flex h-20 items-center gap-3 border-b border-white/8 px-6">
            <div class="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-teal-400 to-emerald-600 shadow-lg shadow-emerald-950/40">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 7h6a4 4 0 0 1 0 8H7V7Z"/><path d="M7 3v18M13 7h4"/></svg>
            </div>
            <div><p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-emerald-400">Commerce OS</p><p class="mt-0.5 font-semibold tracking-tight">{{ page.props.appName }}</p></div>
            <button class="ml-auto rounded-lg p-2 text-slate-400 hover:bg-white/8 hover:text-white lg:hidden" aria-label="Close navigation" @click="emit('close')">×</button>
        </div>

        <nav class="flex-1 overflow-y-auto px-4 py-6">
            <section v-for="section in sections" :key="section.label" class="mb-7">
                <p class="mb-2 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-500">{{ section.label }}</p>
                <div class="space-y-1">
                    <template v-for="item in section.items" :key="item.route">
                        <Link v-if="!item.comingSoon" :href="item.route" class="group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition" :class="isActive(item.route) ? 'bg-emerald-400/12 text-emerald-300 ring-1 ring-inset ring-emerald-400/15' : 'text-slate-400 hover:bg-white/6 hover:text-slate-100'" @click="emit('close')">
                            <AppIcon :name="item.icon" :size="19"/><span>{{ item.label }}</span>
                        </Link>
                        <div v-else class="group flex cursor-default items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-slate-600" :title="`${item.label} is reserved for a later phase`">
                            <AppIcon :name="item.icon" :size="19"/><span>{{ item.label }}</span><span class="ml-auto rounded-full border border-white/8 px-1.5 py-0.5 text-[9px] uppercase tracking-wide text-slate-600">Soon</span>
                        </div>
                    </template>
                </div>
            </section>
        </nav>

        <div class="border-t border-white/8 px-6 py-4 text-xs text-slate-500">
            <div class="flex items-center gap-2"><span class="h-2 w-2 rounded-full bg-emerald-400 shadow-[0_0_10px_rgba(52,211,153,.7)]"/><span>Platform operational</span></div>
        </div>
    </aside>
</template>
