<script setup lang="ts">
import { computed } from 'vue';

interface Point { label: string; sales: number; orders: number }
const props = defineProps<{ points: Point[]; metric: 'sales' | 'orders'; color?: string }>();
const max = computed(() => Math.max(...props.points.map((point) => Number(point[props.metric])), 1));
const visibleLabels = computed(() => Math.max(1, Math.ceil(props.points.length / 7)));
</script>

<template>
    <div class="flex h-56 items-end gap-1.5 sm:gap-2">
        <div v-for="(point, index) in points" :key="`${metric}-${point.label}-${index}`" class="group flex min-w-0 flex-1 flex-col items-center justify-end gap-2">
            <div class="invisible absolute -translate-y-7 rounded-lg bg-slate-950 px-2 py-1 text-xs font-semibold text-white group-hover:visible">
                {{ Number(point[metric]).toLocaleString('zh-CN') }}
            </div>
            <div class="w-full rounded-t-md transition-all" :class="color ?? 'bg-emerald-500'" :style="{ height: `${Math.max((Number(point[metric]) / max) * 170, Number(point[metric]) ? 6 : 2)}px` }" />
            <span class="h-4 truncate text-xs text-slate-400">{{ index % visibleLabels === 0 || index === points.length - 1 ? point.label : '' }}</span>
        </div>
    </div>
</template>
