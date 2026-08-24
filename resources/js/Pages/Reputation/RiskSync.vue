<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Pagination from '../../Components/Navigation/Pagination.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

type SyncState = { uuid: string; status: string; progress_percent: number; processed_rows: number; message: string | null } | null;
type Risk = { uuid: string; origin: string; description: string; severity: string; source: string; recommended_action: string | null; status: string; occurred_at: string | null; created_at: string | null };
type Resource = { uuid: string; description: string; request_type: string; priority: string; owner_name: string | null; status: string; due_date: string | null; created_at: string | null };
type Paginator<T> = { data: T[]; links: Array<{ url: string | null; label: string; active: boolean }>; total: number };

const props = defineProps<{
    store: { id: number; name: string; timezone: string };
    canSync: boolean;
    canManage: boolean;
    dashboard: {
        filters: { date_from: string; date_to: string; status: string; severity: string; source: string };
        counts: { total: number; pending: number; processing: number; watching: number; resolved: number; high_open: number };
        risks: Paginator<Risk>;
        resources: Paginator<Resource>;
        freshness: { last_synced_at: string | null; database_total: number; sync: SyncState };
        source_status: { configured: boolean; has_configuration: boolean; missing_fields: string[] };
    };
}>();

const sourceLabels: Record<string, string> = { trustpilot: 'Trustpilot', website: '官网评论', facebook: 'Facebook', reddit: 'Reddit', threads: 'Threads', multiple: '多平台' };
const severityLabels: Record<string, string> = { low: '低风险', medium: '中风险', high: '高风险', critical: '紧急' };
const riskStatusLabels: Record<string, string> = { pending: '待处理', processing: '处理中', watching: '持续观察', resolved: '已解决', dismissed: '已忽略' };
const resourceStatusLabels: Record<string, string> = { pending: '待处理', processing: '处理中', completed: '已完成', cancelled: '已取消' };
const resourceTypeLabels: Record<string, string> = { content: '内容素材', staff: '人员支持', budget: '预算支持', technical: '技术支持', product: '产品支持' };
const priorityLabels: Record<string, string> = { normal: '常规', important: '重要', urgent: '紧急' };
const filters = ref({ ...props.dashboard.filters });
const riskOpen = ref(false);
const resourceOpen = ref(false);
let pollTimer: number | null = null;

const riskForm = useForm({ description: '', severity: 'medium', source: 'multiple', recommended_action: '', occurred_at: '' });
const resourceForm = useForm({ description: '', request_type: 'content', priority: 'important', owner_name: '', due_date: '' });
const sync = computed(() => props.dashboard.freshness.sync);
const syncing = computed(() => ['queued', 'running'].includes(sync.value?.status ?? ''));
const sourceReady = computed(() => props.dashboard.source_status.has_configuration);

const formatNumber = (value: number) => new Intl.NumberFormat('zh-CN').format(value ?? 0);
const formatDate = (value: string | null, withTime = false) => value
    ? new Intl.DateTimeFormat('zh-CN', withTime ? { dateStyle: 'medium', timeStyle: 'short' } : { dateStyle: 'medium' }).format(new Date(value))
    : '未标日期';
const severityClass = (severity: string) => ({
    critical: 'border-red-300 bg-red-50 text-red-800', high: 'border-rose-200 bg-rose-50 text-rose-700',
    medium: 'border-amber-200 bg-amber-50 text-amber-700', low: 'border-sky-200 bg-sky-50 text-sky-700',
}[severity] ?? 'border-slate-200 bg-slate-50 text-slate-700');

function applyFilters(extra: Record<string, string> = {}) {
    filters.value = { ...filters.value, ...extra };
    router.get('/reputation/risks', filters.value, { preserveState: true, preserveScroll: true, replace: true });
}

function requestSync() {
    if (!props.canSync || !sourceReady.value || syncing.value) return;
    router.post('/reputation/sync', {}, { preserveScroll: true });
}

async function pollSync() {
    if (!props.canSync || !sync.value?.uuid || !syncing.value) return;
    try {
        const response = await fetch(`/reputation/sync/${sync.value.uuid}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) return;
        const payload = await response.json();
        if (['completed', 'failed'].includes(payload.data?.status)) {
            stopPolling();
            router.reload({ only: ['dashboard'] });
        }
    } catch {
        // Keep the current database view if a polling request temporarily fails.
    }
}

function startPolling() {
    stopPolling();
    if (syncing.value) pollTimer = window.setInterval(pollSync, 1800);
}

function stopPolling() {
    if (pollTimer !== null) window.clearInterval(pollTimer);
    pollTimer = null;
}

function createRisk() {
    riskForm.post('/reputation/risks', { preserveScroll: true, onSuccess: () => { riskOpen.value = false; riskForm.reset(); } });
}

function updateRisk(uuid: string, status: string) {
    router.patch(`/reputation/risks/${uuid}`, { status }, { preserveScroll: true });
}

function createResource() {
    resourceForm.post('/reputation/resources', { preserveScroll: true, onSuccess: () => { resourceOpen.value = false; resourceForm.reset(); } });
}

function updateResource(uuid: string, status: string) {
    router.patch(`/reputation/resources/${uuid}`, { status }, { preserveScroll: true });
}

function inputValue(event: Event): string {
    return (event.target as HTMLSelectElement).value;
}

watch(() => props.dashboard.filters, (value) => { filters.value = { ...value }; }, { deep: true });
watch(() => sync.value?.status, startPolling, { immediate: true });
onBeforeUnmount(stopPolling);
</script>

<template>
    <AppLayout>
        <Head title="风险同步" />
        <div class="mx-auto max-w-[1680px] space-y-6 p-4 sm:p-6 lg:p-8">
            <section class="overflow-hidden rounded-3xl bg-gradient-to-br from-slate-950 via-slate-900 to-indigo-950 px-6 py-7 text-white shadow-xl shadow-slate-200/60 sm:px-8">
                <div class="flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
                    <div><div class="mb-3 text-xs font-semibold uppercase tracking-[0.18em] text-indigo-300">舆情监控 / {{ store.name }}</div><h1 class="text-3xl font-bold tracking-tight sm:text-4xl">风险同步</h1><p class="mt-3 max-w-3xl text-sm leading-6 text-slate-300">集中跟进来源表中明确标注的负面记录、1–2 星评价和人工添加风险，并协调资源需求。</p></div>
                    <div class="flex flex-wrap gap-3"><button v-if="canManage" type="button" class="rounded-2xl border border-white/15 bg-white/10 px-5 py-3 text-sm font-bold text-white hover:bg-white/15" @click="resourceOpen = true">添加资源需求</button><button v-if="canManage" type="button" class="rounded-2xl bg-white px-5 py-3 text-sm font-bold text-slate-950 hover:bg-slate-100" @click="riskOpen = true">添加风险</button><button v-if="canSync" type="button" class="rounded-2xl bg-indigo-400 px-5 py-3 text-sm font-bold text-slate-950 hover:bg-indigo-300 disabled:opacity-50" :disabled="!sourceReady || syncing" @click="requestSync">{{ syncing ? `同步中 ${sync?.progress_percent ?? 0}%` : '同步来源数据' }}</button></div>
                </div>
                <div class="mt-6 flex flex-wrap gap-x-5 gap-y-2 text-xs text-slate-400"><span>数据库记录 {{ formatNumber(dashboard.freshness.database_total) }} 条</span><span>{{ dashboard.freshness.last_synced_at ? `上次同步 ${formatDate(dashboard.freshness.last_synced_at, true)}` : '尚未同步' }}</span><span v-if="dashboard.source_status.missing_fields.length">部分来源未配置（{{ dashboard.source_status.missing_fields.length }}）</span></div>
                <div v-if="!sourceReady" class="mt-5 rounded-2xl border border-amber-400/30 bg-amber-300/10 px-4 py-3 text-sm text-amber-100">尚未配置当前项目的舆情飞书数据源，人工风险与资源需求仍可正常使用。</div>
            </section>

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <article v-for="card in [{label:'全部风险',value:dashboard.counts.total,tone:'slate'},{label:'待处理',value:dashboard.counts.pending,tone:'amber'},{label:'处理中',value:dashboard.counts.processing,tone:'indigo'},{label:'持续观察',value:dashboard.counts.watching,tone:'sky'},{label:'高风险未关闭',value:dashboard.counts.high_open,tone:'rose'}]" :key="card.label" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm font-medium text-slate-500">{{ card.label }}</p><p class="mt-3 text-3xl font-bold" :class="card.tone === 'rose' ? 'text-rose-600' : card.tone === 'amber' ? 'text-amber-600' : card.tone === 'indigo' ? 'text-indigo-600' : card.tone === 'sky' ? 'text-sky-600' : 'text-slate-950'">{{ formatNumber(card.value) }}</p></article>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 p-5 sm:p-6"><div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between"><div><h2 class="text-xl font-bold text-slate-950">风险清单</h2><p class="mt-1 text-sm text-slate-500">来源记录会在同步后去重，人工处理状态会被保留。</p></div><div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-6"><input v-model="filters.date_from" type="date" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" aria-label="开始日期"><input v-model="filters.date_to" type="date" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" aria-label="结束日期"><select v-model="filters.status" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm"><option value="">全部状态</option><option v-for="(label, key) in riskStatusLabels" :key="key" :value="key">{{ label }}</option></select><select v-model="filters.severity" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm"><option value="">全部等级</option><option v-for="(label, key) in severityLabels" :key="key" :value="key">{{ label }}</option></select><select v-model="filters.source" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm"><option value="">全部平台</option><option v-for="(label, key) in sourceLabels" :key="key" :value="key">{{ label }}</option></select><button type="button" class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white" @click="applyFilters()">筛选</button></div></div></div>
                <div class="space-y-3 p-5 sm:p-6">
                    <article v-for="risk in dashboard.risks.data" :key="risk.uuid" class="rounded-2xl border p-5" :class="severityClass(risk.severity)">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="min-w-0 flex-1"><div class="flex flex-wrap items-center gap-2"><span class="rounded-full bg-white/75 px-2.5 py-1 text-xs font-bold">{{ severityLabels[risk.severity] }}</span><span class="text-xs font-semibold">{{ sourceLabels[risk.source] ?? risk.source }}</span><span class="text-xs opacity-65">{{ risk.origin === 'source' ? '来源同步' : '人工添加' }}</span><span class="text-xs opacity-65">{{ formatDate(risk.occurred_at) }}</span></div><p class="mt-4 whitespace-pre-wrap text-sm font-semibold leading-6">{{ risk.description }}</p><p v-if="risk.recommended_action" class="mt-3 rounded-xl bg-white/55 px-3 py-2.5 text-sm leading-6"><span class="font-bold">处理建议：</span>{{ risk.recommended_action }}</p></div>
                            <div class="flex shrink-0 items-center gap-2"><span class="rounded-full bg-white/75 px-3 py-1.5 text-xs font-bold">{{ riskStatusLabels[risk.status] }}</span><select v-if="canManage" :value="risk.status" class="rounded-xl border border-white/80 bg-white px-3 py-2 text-xs font-semibold text-slate-700" aria-label="更新风险状态" @change="updateRisk(risk.uuid, inputValue($event))"><option value="pending">待处理</option><option value="processing">处理中</option><option value="watching">持续观察</option><option value="resolved">已解决</option><option value="dismissed">已忽略</option></select></div>
                        </div>
                    </article>
                    <div v-if="dashboard.risks.data.length === 0" class="rounded-2xl border border-dashed border-slate-200 px-6 py-14 text-center text-sm text-slate-500">当前筛选条件下没有风险项。</div>
                    <Pagination :links="dashboard.risks.links" />
                </div>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 p-5 sm:p-6"><div><h2 class="text-xl font-bold text-slate-950">资源支持需求</h2><p class="mt-1 text-sm text-slate-500">跟进内容、人员、预算、技术与产品支持。</p></div><button v-if="canManage" type="button" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="resourceOpen = true">添加需求</button></div>
                <div class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6 xl:grid-cols-3">
                    <article v-for="resource in dashboard.resources.data" :key="resource.uuid" class="rounded-2xl border border-slate-200 p-5"><div class="flex items-start justify-between gap-3"><div><span class="rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-bold text-indigo-700">{{ resourceTypeLabels[resource.request_type] }}</span><span class="ml-2 text-xs font-semibold" :class="resource.priority === 'urgent' ? 'text-rose-600' : 'text-slate-500'">{{ priorityLabels[resource.priority] }}</span></div><span class="text-xs font-semibold text-slate-500">{{ resourceStatusLabels[resource.status] }}</span></div><p class="mt-4 min-h-12 text-sm font-semibold leading-6 text-slate-800">{{ resource.description }}</p><div class="mt-4 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500"><span>{{ resource.owner_name || '未指定对接人' }}</span><span>{{ resource.due_date ? `截止 ${resource.due_date}` : '未设截止日期' }}</span></div><select v-if="canManage" :value="resource.status" class="mt-4 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" aria-label="更新资源需求状态" @change="updateResource(resource.uuid, inputValue($event))"><option value="pending">待处理</option><option value="processing">处理中</option><option value="completed">已完成</option><option value="cancelled">已取消</option></select></article>
                    <div v-if="dashboard.resources.data.length === 0" class="col-span-full rounded-2xl border border-dashed border-slate-200 px-6 py-12 text-center text-sm text-slate-500">暂无资源支持需求。</div>
                </div>
                <div class="px-5 pb-6 sm:px-6"><Pagination :links="dashboard.resources.links" /></div>
            </section>
        </div>

        <div v-if="riskOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-4" @click.self="riskOpen = false"><form class="w-full max-w-2xl rounded-3xl bg-white p-6 shadow-2xl sm:p-8" @submit.prevent="createRisk"><div class="flex items-start justify-between"><div><h2 class="text-xl font-bold text-slate-950">添加风险项</h2><p class="mt-1 text-sm text-slate-500">手动记录明确发现的舆情风险。</p></div><button type="button" class="text-2xl text-slate-400" @click="riskOpen = false">×</button></div><label class="mt-6 block text-sm font-semibold text-slate-700">风险描述 *<textarea v-model="riskForm.description" required maxlength="5000" rows="4" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5" placeholder="描述发生了什么、影响范围和已知事实" /></label><div class="mt-4 grid gap-4 sm:grid-cols-3"><label class="text-sm font-semibold text-slate-700">风险等级<select v-model="riskForm.severity" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5"><option v-for="(label, key) in severityLabels" :key="key" :value="key">{{ label }}</option></select></label><label class="text-sm font-semibold text-slate-700">来源平台<select v-model="riskForm.source" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5"><option v-for="(label, key) in sourceLabels" :key="key" :value="key">{{ label }}</option></select></label><label class="text-sm font-semibold text-slate-700">发生时间<input v-model="riskForm.occurred_at" type="datetime-local" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5"></label></div><label class="mt-4 block text-sm font-semibold text-slate-700">建议处理方案<textarea v-model="riskForm.recommended_action" maxlength="5000" rows="3" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5" /></label><p v-if="riskForm.hasErrors" class="mt-3 text-sm text-rose-600">请检查必填项和输入长度。</p><div class="mt-6 flex justify-end gap-3"><button type="button" class="rounded-xl border border-slate-200 px-5 py-2.5 text-sm font-semibold" @click="riskOpen = false">取消</button><button type="submit" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50" :disabled="riskForm.processing">添加</button></div></form></div>

        <div v-if="resourceOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-4" @click.self="resourceOpen = false"><form class="w-full max-w-2xl rounded-3xl bg-white p-6 shadow-2xl sm:p-8" @submit.prevent="createResource"><div class="flex items-start justify-between"><div><h2 class="text-xl font-bold text-slate-950">添加资源支持需求</h2><p class="mt-1 text-sm text-slate-500">明确支持内容、优先级与对接信息。</p></div><button type="button" class="text-2xl text-slate-400" @click="resourceOpen = false">×</button></div><label class="mt-6 block text-sm font-semibold text-slate-700">需求描述 *<textarea v-model="resourceForm.description" required maxlength="5000" rows="4" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5" placeholder="描述需要什么支持以及期望结果" /></label><div class="mt-4 grid gap-4 sm:grid-cols-2"><label class="text-sm font-semibold text-slate-700">需求类型<select v-model="resourceForm.request_type" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5"><option v-for="(label, key) in resourceTypeLabels" :key="key" :value="key">{{ label }}</option></select></label><label class="text-sm font-semibold text-slate-700">优先级<select v-model="resourceForm.priority" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5"><option v-for="(label, key) in priorityLabels" :key="key" :value="key">{{ label }}</option></select></label><label class="text-sm font-semibold text-slate-700">对接部门 / 人<input v-model="resourceForm.owner_name" maxlength="120" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5" placeholder="如：产品部"></label><label class="text-sm font-semibold text-slate-700">截止日期<input v-model="resourceForm.due_date" type="date" class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5"></label></div><p v-if="resourceForm.hasErrors" class="mt-3 text-sm text-rose-600">请检查必填项和输入长度。</p><div class="mt-6 flex justify-end gap-3"><button type="button" class="rounded-xl border border-slate-200 px-5 py-2.5 text-sm font-semibold" @click="resourceOpen = false">取消</button><button type="submit" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50" :disabled="resourceForm.processing">添加</button></div></form></div>
    </AppLayout>
</template>
