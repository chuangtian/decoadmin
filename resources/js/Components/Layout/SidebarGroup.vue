<script setup lang="ts">
import type { MenuItem } from '../../config/menu';
import AppIcon from './AppIcon.vue';
import SidebarItem from './SidebarItem.vue';

const props = defineProps<{ item: MenuItem; expanded: boolean; currentUrl: string }>();
const emit = defineEmits<{ toggle: []; navigate: [] }>();
const isActive = (route?: string) => Boolean(route && (props.currentUrl === route || props.currentUrl.startsWith(`${route}/`)));
</script>

<template>
    <section>
        <button
            type="button"
            class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-semibold transition"
            :class="item.children?.some((child) => isActive(child.route)) ? 'text-white' : 'text-slate-300 hover:bg-white/6 hover:text-white'"
            :aria-expanded="expanded"
            @click="emit('toggle')"
        >
            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg" :class="item.children?.some((child) => isActive(child.route)) ? 'bg-emerald-400/12 text-emerald-300' : 'bg-white/4 text-slate-500'">
                <AppIcon :name="item.icon" :size="18" />
            </span>
            <span class="truncate">{{ item.name }}</span>
            <svg class="ml-auto h-4 w-4 shrink-0 text-slate-600 transition-transform duration-200" :class="expanded ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd" /></svg>
        </button>
        <div v-show="expanded" class="mt-1 space-y-0.5">
            <SidebarItem v-for="child in item.children" :key="child.route" :item="child" :active="isActive(child.route)" @navigate="emit('navigate')" />
        </div>
    </section>
</template>
