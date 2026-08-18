<script setup lang="ts">
import type { ShopifyConnectionStatus } from '../../types';

withDefaults(defineProps<{
    status: ShopifyConnectionStatus | 'pending';
    size?: 'sm' | 'md';
}>(), {
    size: 'sm',
});

const statuses = {
    connected: { label: '已连接', badge: 'bg-emerald-50 text-emerald-700 ring-emerald-600/15', dot: 'bg-emerald-500' },
    warning: { label: '连接警告', badge: 'bg-amber-50 text-amber-700 ring-amber-600/15', dot: 'bg-amber-400' },
    invalid: { label: '授权失效', badge: 'bg-rose-50 text-rose-700 ring-rose-600/15', dot: 'bg-rose-500' },
    disconnected: { label: '已断开', badge: 'bg-slate-100 text-slate-600 ring-slate-500/15', dot: 'bg-slate-400' },
    pending: { label: '待连接', badge: 'bg-amber-50 text-amber-700 ring-amber-600/15', dot: 'bg-amber-400' },
} as const;
</script>

<template>
    <span
        class="inline-flex items-center rounded-full font-semibold ring-1 ring-inset"
        :class="[
            statuses[status].badge,
            size === 'md' ? 'gap-2 px-3 py-1.5 text-sm' : 'gap-1.5 px-2.5 py-1 text-xs',
        ]"
        :aria-label="`Shopify 连接状态：${statuses[status].label}`"
    >
        <span class="rounded-full" :class="[statuses[status].dot, size === 'md' ? 'h-2.5 w-2.5' : 'h-2 w-2']" />
        {{ statuses[status].label }}
    </span>
</template>
