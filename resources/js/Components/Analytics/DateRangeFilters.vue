<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

const props = defineProps<{
    action: string;
    period: { days: number; from: string; to: string; include_test: boolean; include_cancelled: boolean };
    extra?: Record<string, string | number | boolean>;
    showOrderOptions?: boolean;
}>();

const form = reactive({
    days: props.period.days,
    date_from: props.period.from,
    date_to: props.period.to,
    include_test: props.period.include_test,
    include_cancelled: props.period.include_cancelled,
});

const query = typeof window === 'undefined' ? null : new URLSearchParams(window.location.search);
const activePreset = ref<number | null>(query?.has('date_from')
    ? null
    : Number(query?.get('days') ?? props.period.days));

const preset = (days: number) => {
    form.days = days;
    form.date_from = '';
    form.date_to = '';
    activePreset.value = days;

    router.get(props.action, {
        days,
        include_test: form.include_test,
        include_cancelled: form.include_cancelled,
        ...(props.extra ?? {}),
    }, {
        preserveState: false,
        preserveScroll: true,
        replace: true,
    });
};

const useCustomRange = () => {
    activePreset.value = null;
};

const apply = () => {
    router.get(props.action, { ...form, ...(props.extra ?? {}) }, {
        // The server normalizes presets, date ranges and boolean flags. Recreate the
        // filter component from the returned props so its visible state always
        // matches the query that produced the report.
        preserveState: false,
        preserveScroll: true,
        replace: true,
    });
};
</script>

<template>
    <form class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm" @submit.prevent="apply">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-end">
            <div class="flex flex-wrap gap-2">
                <button v-for="days in [7, 30, 90]" :key="days" type="button" class="rounded-xl px-3.5 py-2 text-sm font-semibold transition" :class="activePreset === days ? 'bg-slate-950 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'" @click="preset(days)">
                    {{ days }} 天
                </button>
            </div>
            <label class="min-w-0 flex-1 text-xs font-semibold text-slate-500">开始日期
                <input v-model="form.date_from" type="date" class="mt-1.5 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm text-slate-800" required @input="useCustomRange">
            </label>
            <label class="min-w-0 flex-1 text-xs font-semibold text-slate-500">结束日期
                <input v-model="form.date_to" type="date" class="mt-1.5 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm text-slate-800" required @input="useCustomRange">
            </label>
            <div v-if="showOrderOptions !== false" class="flex flex-wrap gap-4 pb-2 text-sm font-medium text-slate-600">
                <label class="flex items-center gap-2"><input v-model="form.include_test" type="checkbox" class="rounded border-slate-300 text-emerald-600">包含测试订单</label>
                <label class="flex items-center gap-2"><input v-model="form.include_cancelled" type="checkbox" class="rounded border-slate-300 text-emerald-600">包含取消订单</label>
            </div>
            <button type="submit" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">应用筛选</button>
        </div>
    </form>
</template>
