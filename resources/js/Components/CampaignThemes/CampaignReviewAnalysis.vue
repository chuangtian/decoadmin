<script setup lang="ts">
defineProps<{
    summary: string | null;
    diagnosis: string | null;
    optimization: string | null;
}>();
</script>

<template>
    <section class="min-w-0 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
        <div>
            <h2 class="text-lg font-semibold text-slate-950">活动总结分析</h2>
            <p class="mt-1 text-xs text-slate-400">内容来自飞书活动字段 · 本地数据库</p>
        </div>

        <article class="mt-5 min-w-0 rounded-2xl border border-slate-200 bg-slate-50/60 p-4 sm:p-5">
            <h3 class="text-base font-semibold text-slate-900">📝 活动总结</h3>
            <p v-if="summary" class="mt-4 whitespace-pre-line break-words text-sm leading-7 text-slate-700">{{ summary }}</p>
            <p v-else class="mt-4 text-sm text-slate-400">暂无活动总结</p>
        </article>

        <div class="mt-4 grid min-w-0 gap-4 lg:grid-cols-2">
            <article class="min-w-0 rounded-2xl border border-slate-200 bg-slate-50/60 p-4 sm:p-5">
                <h3 class="text-base font-semibold text-slate-900">🔍 问题诊断</h3>
                <p v-if="diagnosis" class="mt-4 whitespace-pre-line break-words text-sm leading-7 text-slate-700">{{ diagnosis }}</p>
                <p v-else class="mt-4 text-sm text-slate-400">暂无问题诊断</p>
            </article>

            <article class="min-w-0 rounded-2xl border border-slate-200 bg-slate-50/60 p-4 sm:p-5">
                <h3 class="text-base font-semibold text-slate-900">💡 优化分析</h3>
                <p v-if="optimization" class="mt-4 whitespace-pre-line break-words text-sm leading-7 text-slate-700">{{ optimization }}</p>
                <p v-else class="mt-4 text-sm text-slate-400">暂无优化分析</p>
            </article>
        </div>
    </section>
</template>
