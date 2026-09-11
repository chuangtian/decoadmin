<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { MenuItem } from '../../config/menu';

defineProps<{ item: MenuItem; active: boolean }>();
const emit = defineEmits<{ navigate: [] }>();
</script>

<template>
    <Link
        v-if="item.route && !item.comingSoon"
        :href="item.route"
        class="group flex items-center gap-2.5 rounded-xl py-2 pr-3 pl-10 text-[13px] font-medium transition"
        :class="active ? 'bg-emerald-400/12 text-emerald-300 ring-1 ring-inset ring-emerald-400/15' : 'text-slate-400 hover:bg-white/6 hover:text-slate-100'"
        @click="emit('navigate')"
    >
        <span class="h-1.5 w-1.5 shrink-0 rounded-full transition" :class="active ? 'bg-emerald-300' : 'bg-slate-700 group-hover:bg-slate-500'" />
        <span class="truncate">{{ item.name }}</span>
    </Link>
    <div
        v-else
        class="group flex cursor-default items-center gap-2.5 rounded-xl py-2 pr-3 pl-10 text-[13px] font-medium text-slate-600"
        :title="`${item.name}将在后续阶段开放`"
    >
        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-slate-800" />
        <span class="truncate">{{ item.name }}</span>
        <span class="ml-auto shrink-0 rounded-full border border-white/8 px-1.5 py-0.5 text-xs tracking-wide text-slate-600">即将开放</span>
    </div>
</template>
