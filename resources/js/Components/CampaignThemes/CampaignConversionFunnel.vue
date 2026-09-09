<script setup lang="ts">
interface FunnelStage {
    key: string;
    label: string;
    sessions: number;
    rate_percent: number;
    comparison_rate_percent: number | null;
}

defineProps<{
    stages: FunnelStage[];
    available: boolean;
    pending: boolean;
    message: string | null;
    comparisonName: string | null;
}>();

const integer = (value: number) => new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value);
const change = (stage: FunnelStage) => stage.comparison_rate_percent === null
    ? null
    : Math.round((stage.rate_percent - stage.comparison_rate_percent) * 100) / 100;
</script>

<template>
    <section class="min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <header class="border-b border-slate-100 px-5 py-5 sm:px-6">
            <h2 class="text-lg font-semibold text-slate-950">活动期间转化漏斗</h2>
            <p class="mt-1 text-xs text-slate-400">Shopify 会话口径<span v-if="comparisonName"> · 对比 {{ comparisonName }}</span></p>
        </header>

        <div v-if="available && stages.length" class="px-5 py-6 sm:px-6">
            <div class="grid gap-4 md:grid-cols-4">
                <article v-for="(stage, index) in stages" :key="stage.key" class="relative border-slate-200 md:border-r md:pr-4 md:last:border-r-0">
                    <p class="text-sm font-medium text-slate-500">{{ stage.label }}</p>
                    <strong class="mt-2 block text-3xl font-semibold tabular-nums text-slate-950">{{ stage.rate_percent.toFixed(2) }}%</strong>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <span class="text-sm tabular-nums text-slate-500">{{ integer(stage.sessions) }}</span>
                        <span v-if="change(stage) !== null" class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="(change(stage) ?? 0) >= 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'">
                            {{ (change(stage) ?? 0) >= 0 ? '+' : '' }}{{ change(stage)?.toFixed(2) }}pp
                        </span>
                    </div>
                    <span v-if="index < stages.length - 1" class="absolute -right-2 top-9 hidden text-slate-300 md:block">›</span>
                </article>
            </div>

            <div class="mt-7 flex h-52 items-end gap-1 overflow-hidden rounded-2xl bg-slate-50 p-4 sm:gap-2">
                <div
                    v-for="(stage, index) in stages"
                    :key="`funnel-${stage.key}`"
                    class="relative flex h-full min-w-0 flex-1 items-end justify-center overflow-hidden rounded-lg bg-indigo-600 text-white transition"
                    :style="{ height: `${Math.max(22, 100 - index * 22)}%`, opacity: `${1 - index * 0.12}` }"
                >
                    <strong class="mb-4 truncate px-2 text-sm tabular-nums sm:text-base">{{ integer(stage.sessions) }}</strong>
                </div>
            </div>
            <p class="mt-4 text-xs leading-5 text-slate-400">访问、加购、到达结账和完成成交全部来自 Shopify 同一套会话报表，未混用飞书订单数。</p>
        </div>
        <div v-else class="grid min-h-64 place-items-center px-6 py-12 text-center">
            <div>
                <span class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-slate-100 text-xl text-slate-400">▽</span>
                <p class="mt-4 font-semibold text-slate-700">{{ pending ? 'Shopify 漏斗正在准备' : '暂无转化漏斗数据' }}</p>
                <p class="mt-1 text-sm text-slate-400">{{ message || '稍后将自动刷新。' }}</p>
            </div>
        </div>
    </section>
</template>
