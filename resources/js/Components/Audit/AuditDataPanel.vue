<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    title: string;
    description: string;
    values: Record<string, unknown> | unknown[] | null;
    tone?: 'slate' | 'emerald' | 'blue';
}>();

const labels: Record<string, string> = {
    status: '状态',
    reason: '原因',
    previous_status: '原状态',
    new_status: '新状态',
    api_version: 'API 版本',
    webhook_id: 'Webhook ID',
    topic: '事件类型',
    attempts: '尝试次数',
};

const entries = computed(() => Object.entries(props.values ?? {}));
const keyLabel = (key: string) => labels[key] ?? key.replaceAll('_', ' ');
const valueLabel = (value: unknown) => {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? '是' : '否';
    if (typeof value === 'object') return JSON.stringify(value, null, 2);
    return String(value);
};

const toneClasses = computed(() => ({
    slate: 'border-slate-200 bg-white',
    emerald: 'border-emerald-200 bg-emerald-50/40',
    blue: 'border-blue-200 bg-blue-50/40',
}[props.tone ?? 'slate']));
</script>

<template>
    <section class="rounded-2xl border p-5 shadow-sm sm:p-6" :class="toneClasses">
        <h2 class="font-semibold text-slate-950">{{ title }}</h2>
        <p class="mt-1 text-sm text-slate-500">{{ description }}</p>
        <dl v-if="entries.length" class="mt-5 divide-y divide-slate-200/70">
            <div v-for="([key, value]) in entries" :key="key" class="grid gap-1 py-3 first:pt-0 last:pb-0 sm:grid-cols-[150px_minmax(0,1fr)] sm:gap-4">
                <dt class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ keyLabel(key) }}</dt>
                <dd class="whitespace-pre-wrap break-words font-mono text-xs leading-6 text-slate-700">{{ valueLabel(value) }}</dd>
            </div>
        </dl>
        <p v-else class="mt-5 rounded-xl bg-white/70 px-4 py-3 text-sm text-slate-400">无记录</p>
    </section>
</template>
