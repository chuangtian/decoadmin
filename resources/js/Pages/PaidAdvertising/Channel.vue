<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useToast } from '../../composables/useToast';

type ChannelState = 'not_configured' | 'pending' | 'syncing' | 'backfilling' | 'completed' | 'failed' | 'partial_failed';
type ChannelStatus = {
    schema: 'advertising-channel-sync-status-v1'; channel: string; label: string; configured: boolean;
    settings_url: string; state: ChannelState; mode: string | null; data_ready: boolean;
    progress_percent: number; completed_chunks: number; total_chunks: number; last_metric_date: string | null;
    data_synced_at: string | null; last_success_at: string | null; message: string | null;
};

const props = defineProps<{ store: { id: number; name: string }; channelStatus: ChannelStatus }>();
const status = ref(props.channelStatus);
const checking = ref(false);
const toast = useToast();
let poller: ReturnType<typeof setInterval> | null = null;
const polling = computed(() => ['pending', 'syncing', 'backfilling'].includes(status.value.state));
const progressWidth = computed(() => `${Math.max(2, Math.min(100, status.value.progress_percent || 0))}%`);
const freshness = computed(() => status.value.last_metric_date ? `数据截止 ${status.value.last_metric_date}` : '等待首批数据');

const stopPolling = () => { if (poller !== null) { clearInterval(poller); poller = null; } };
const refreshStatus = async () => {
    if (checking.value) return;
    checking.value = true;
    const previous = status.value.state;
    try {
        const response = await fetch(`/paid-advertising/${encodeURIComponent(status.value.channel)}/status`, {
            credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error('status failed');
        status.value = (await response.json() as { data: ChannelStatus }).data;
        if (['pending', 'syncing'].includes(previous) && status.value.data_ready) toast.success(`${status.value.label} 最近 7 天数据已可使用。`);
        if (status.value.state === 'failed' && previous !== 'failed') toast.error(`${status.value.label} 同步失败，请检查授权。`);
        if (!polling.value) stopPolling();
    } catch { /* Keep the background task independent from polling failures. */ }
    finally { checking.value = false; }
};
onMounted(() => { if (polling.value) { refreshStatus(); poller = setInterval(refreshStatus, 5000); } });
onBeforeUnmount(stopPolling);
</script>

<template>
    <Head :title="status.label" />
    <AppLayout :breadcrumbs="[{ label: '工作台', href: '/dashboard' }, { label: '付费广告' }, { label: status.label }]">
        <div class="mx-auto max-w-6xl space-y-6">
            <header class="flex flex-col gap-4 border-b border-slate-200 pb-5 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-600">当前店铺 · {{ store.name }}</p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">{{ status.label }}</h1>
                    <p class="mt-2 text-sm text-slate-500">广告账户与日指标按店铺独立同步，页面只读取本地数据库。</p>
                </div>
                <span class="inline-flex w-fit items-center gap-2 rounded-full px-3 py-1.5 text-xs font-semibold" :class="status.configured ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">
                    <i class="h-2 w-2 rounded-full" :class="status.configured ? 'bg-emerald-500' : 'bg-amber-500'" />
                    {{ status.configured ? '凭证已配置' : '尚未配置' }}
                </span>
            </header>

            <section v-if="status.state === 'not_configured'" class="rounded-3xl border border-amber-200 bg-white px-7 py-10 shadow-sm sm:px-10">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-amber-600">需要完成配置</p>
                <h2 class="mt-2 text-xl font-semibold text-slate-950">{{ status.label }} 尚未配置</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">配置完成后会先同步账户信息和最近 7 天数据，随后在后台补齐最近半年。</p>
                <Link :href="status.settings_url" class="mt-6 inline-flex rounded-xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800">前往配置</Link>
            </section>

            <section v-else class="overflow-hidden rounded-3xl border bg-white shadow-sm" :class="status.data_ready ? 'border-emerald-200' : 'border-blue-200'">
                <div class="h-1 bg-gradient-to-r from-blue-600 via-cyan-400 to-emerald-400" />
                <div class="px-7 py-8 sm:px-10">
                    <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.18em]" :class="status.data_ready ? 'text-emerald-600' : 'text-blue-600'">
                                {{ status.data_ready ? '首批数据已就绪' : '后台静默同步' }}
                            </p>
                            <h2 class="mt-2 text-xl font-semibold text-slate-950">
                                {{ status.state === 'backfilling' ? '页面已可用，正在补齐历史' : status.data_ready ? '数据已可使用' : '正在准备最近 7 天数据' }}
                            </h2>
                            <p class="mt-2 text-sm leading-6 text-slate-500">{{ status.message }}</p>
                        </div>
                        <div class="rounded-2xl bg-slate-50 px-5 py-4 ring-1 ring-slate-100">
                            <p class="text-xs font-medium text-slate-400">数据新鲜度</p>
                            <p class="mt-1 text-sm font-semibold text-slate-900">{{ freshness }}</p>
                        </div>
                    </div>
                    <div v-if="polling && status.total_chunks" class="mt-7">
                        <div class="mb-2 flex justify-between text-xs font-semibold"><span class="text-slate-500">{{ status.completed_chunks }} / {{ status.total_chunks }} 个分片</span><span class="text-blue-600">{{ status.progress_percent.toFixed(1) }}%</span></div>
                        <div class="h-3 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-gradient-to-r from-blue-600 to-cyan-400 transition-[width] duration-700" :style="{ width: progressWidth }" /></div>
                    </div>
                    <div v-if="status.data_ready" class="mt-7 rounded-2xl border border-dashed border-slate-200 bg-slate-50/70 px-5 py-8 text-center text-sm text-slate-500">
                        数据模板将在下一步接入；当前账户与日指标已写入店铺独立数据库。
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
