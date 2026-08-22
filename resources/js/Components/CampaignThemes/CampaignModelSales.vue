<script setup lang="ts">
interface ModelSalesItem {
    key: string;
    name: string;
    units: number;
    total_sales: number;
    share_percent: number;
    comparison_units: number;
    change_percent: number | null;
}

defineProps<{
    models: ModelSalesItem[];
    totalUnits: number;
    available: boolean;
    pending: boolean;
    message: string | null;
    comparisonAvailable: boolean;
}>();

const integer = (value: number) => new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value);
</script>

<template>
    <section class="min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <header class="flex flex-col gap-2 border-b border-slate-100 px-5 py-5 sm:flex-row sm:items-end sm:justify-between sm:px-6">
            <div>
                <h2 class="text-lg font-semibold text-slate-950">车型净销售数量占比</h2>
                <p class="mt-1 text-xs text-slate-400">数据源 Shopify · 按商品标题归类 · 已计入退货与冲销</p>
            </div>
            <p v-if="available" class="text-sm font-semibold tabular-nums text-slate-600">合计 {{ integer(totalUnits) }} 辆</p>
        </header>

        <div v-if="available && models.length" class="divide-y divide-slate-100 px-5 sm:px-6">
            <article v-for="model in models" :key="model.key" class="grid gap-3 py-5 lg:grid-cols-[minmax(180px,0.8fr)_minmax(280px,2fr)_100px_150px] lg:items-center">
                <div class="min-w-0">
                    <h3 class="truncate font-semibold text-slate-900" :title="model.name">{{ model.name }}</h3>
                    <p class="mt-1 text-xs text-slate-400">{{ integer(model.units) }} 辆</p>
                </div>
                <div class="h-3 overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full rounded-full bg-emerald-500" :style="{ width: `${Math.max(1.5, model.share_percent)}%` }" />
                </div>
                <strong class="text-right text-lg font-semibold tabular-nums text-emerald-700">{{ model.share_percent.toFixed(1) }}%</strong>
                <p v-if="comparisonAvailable" class="text-right text-xs font-semibold tabular-nums" :class="model.change_percent === null || model.change_percent >= 0 ? 'text-emerald-600' : 'text-rose-600'">
                    {{ model.change_percent === null ? '对比期无销量' : `${model.change_percent >= 0 ? '+' : ''}${model.change_percent.toFixed(1)}%` }}
                </p>
            </article>
        </div>
        <div v-else class="grid min-h-64 place-items-center px-6 py-12 text-center">
            <div>
                <span class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-slate-100 text-xl text-slate-400">□</span>
                <p class="mt-4 font-semibold text-slate-700">{{ pending ? 'Shopify 车型销量正在准备' : '该活动周期内暂无可识别车型销量数据' }}</p>
                <p class="mt-1 text-sm text-slate-400">{{ message || '车型按 Shopify 商品标题识别。' }}</p>
            </div>
        </div>
    </section>
</template>
