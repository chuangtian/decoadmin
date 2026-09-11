<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';

type Row = {
    uuid: string; reference_no: string; request_type: string; priority: string; description: string;
    requester_department: string | null; requester: string; designer_id: number | null; designer: string | null;
    quantity: number; requested_on: string; planned_delivery_date: string; actual_delivery_date: string | null;
    status: string; is_delayed: boolean; delay_days: number; revision_count: number; delivery_note: string | null;
};
type PageLink = { url: string | null; label: string; active: boolean };
type PageData = { data: Row[]; current_page: number; last_page: number; from: number | null; to: number | null; total: number; links: PageLink[] };
type Summary = { total: number; pending: number; in_progress: number; completed: number; overdue: number; on_time_rate: number | null; average_turnaround_days: number | null; revision_count: number; status_counts: Record<string, number> };

const props = defineProps<{
    store: { id: number; name: string; timezone: string };
    scope: 'mine' | 'all'; today: string; canCreate: boolean; canManage: boolean;
    filters: { tab: 'overview' | 'list'; status: string; priority: string; type: string; search: string };
    options: { types: string[]; priorities: string[]; statuses: string[]; designers: Array<{ id: number; name: string }> };
    summary: Summary; requests: PageData;
}>();

const typeLabels: Record<string, string> = { site_banner: '站内 Banner', edm: 'EDM', social_media: '社媒素材', product_detail: '产品详情', video: '视频', other: '其他' };
const priorityLabels: Record<string, string> = { urgent: '紧急', high: '高', medium: '中', low: '低' };
const statusLabels: Record<string, string> = { pending: '待分配', assigned: '已分配', in_progress: '设计中', review: '待确认', completed: '已完成', cancelled: '已取消' };
const statusOrder = ['pending', 'assigned', 'in_progress', 'review', 'completed', 'cancelled'];
const statusColors: Record<string, string> = { pending: '#f59e0b', assigned: '#6366f1', in_progress: '#2563eb', review: '#8b5cf6', completed: '#10b981', cancelled: '#94a3b8' };
const activeTab = ref(props.filters.tab);
const createOpen = ref(false);
const editOpen = ref(false);
const selected = ref<Row | null>(null);
const filters = ref({ status: props.filters.status, priority: props.filters.priority, type: props.filters.type, search: props.filters.search });
const createForm = useForm({ request_type: 'site_banner', priority: 'medium', description: '', requester_department: '', quantity: 1, planned_delivery_date: '' });
const editForm = useForm({ designer_id: null as number | null, status: 'pending', planned_delivery_date: '', actual_delivery_date: '', revision_count: 0, delivery_note: '' });

const maxStatusCount = computed(() => Math.max(1, ...Object.values(props.summary.status_counts)));
const scopeLabel = computed(() => props.scope === 'all' ? '全部人员需求' : '我的需求');
watch(() => props.filters, value => {
    activeTab.value = value.tab;
    filters.value = { status: value.status, priority: value.priority, type: value.type, search: value.search };
});

function query(overrides: Record<string, string | number> = {}): Record<string, string | number> {
    const values: Record<string, string | number> = { tab: activeTab.value, ...filters.value, ...overrides };
    Object.keys(values).forEach(key => { if (values[key] === '') delete values[key]; });
    return values;
}
function selectTab(tab: 'overview' | 'list'): void {
    activeTab.value = tab;
    router.get('/design-requests', query({ tab }), { preserveState: true, preserveScroll: true, replace: true });
}
function applyFilters(): void { router.get('/design-requests', query({ tab: 'list', page: 1 }), { preserveState: true, replace: true }); }
function resetFilters(): void {
    filters.value = { status: '', priority: '', type: '', search: '' };
    applyFilters();
}
function go(url: string | null): void { if (url) router.get(url, {}, { preserveState: true, preserveScroll: true }); }
function submitRequest(): void {
    createForm.post('/design-requests', {
        preserveScroll: true,
        onSuccess: () => { createOpen.value = false; createForm.reset(); createForm.request_type = 'site_banner'; createForm.priority = 'medium'; createForm.quantity = 1; },
    });
}
function openCreate(): void {
    createForm.clearErrors();
    createOpen.value = true;
}
function openEdit(row: Row): void {
    selected.value = row;
    editForm.clearErrors();
    editForm.designer_id = row.designer_id;
    editForm.status = row.status;
    editForm.planned_delivery_date = row.planned_delivery_date;
    editForm.actual_delivery_date = row.actual_delivery_date ?? '';
    editForm.revision_count = row.revision_count;
    editForm.delivery_note = row.delivery_note ?? '';
    editOpen.value = true;
}
function saveRequest(): void {
    if (!selected.value) return;
    editForm.put(`/design-requests/${selected.value.uuid}`, { preserveScroll: true, onSuccess: () => { editOpen.value = false; selected.value = null; } });
}
function paginationLabel(label: string): string {
    if (label.includes('Previous')) return '上一页';
    if (label.includes('Next')) return '下一页';
    return label.replace(/&laquo;|&raquo;/g, '').trim();
}
</script>

<template>
    <Head title="设计需求" />
    <AppLayout :breadcrumbs="[{ label: '工作台' }, { label: '设计需求' }]">
        <main class="mx-auto w-full max-w-[1680px] space-y-5">
            <header class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <div class="mb-2 flex items-center gap-2"><span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">{{ scopeLabel }}</span><span class="text-xs text-slate-400">{{ store.name }}</span></div>
                    <h1 class="text-2xl font-bold tracking-tight text-slate-950">设计需求管理</h1>
                    <p class="mt-1.5 text-sm text-slate-500">提报、分配并跟进设计交付，所有数据按登录账号自动隔离。</p>
                </div>
                <button v-if="canCreate" type="button" class="h-11 rounded-xl bg-blue-600 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700" @click="openCreate">+ 提报新需求</button>
            </header>

            <nav class="flex gap-1 border-b border-slate-200" aria-label="设计需求视图">
                <button type="button" class="border-b-2 px-4 py-3 text-sm font-semibold" :class="activeTab === 'overview' ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500'" @click="selectTab('overview')">效率总览</button>
                <button type="button" class="border-b-2 px-4 py-3 text-sm font-semibold" :class="activeTab === 'list' ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500'" @click="selectTab('list')">需求列表 <span class="ml-1 text-xs">{{ summary.total }}</span></button>
            </nav>

            <template v-if="activeTab === 'overview'">
                <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <article v-for="card in [
                        { label: '需求总数', value: summary.total, hint: scopeLabel, dot: 'bg-blue-500' },
                        { label: '处理中', value: summary.in_progress, hint: '已分配、设计中及待确认', dot: 'bg-indigo-500' },
                        { label: '已完成', value: summary.completed, hint: summary.on_time_rate === null ? '暂无交付数据' : `按时交付率 ${summary.on_time_rate}%`, dot: 'bg-emerald-500' },
                        { label: '已延期', value: summary.overdue, hint: '未完成且已超过计划日期', dot: 'bg-rose-500' },
                    ]" :key="card.label" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-center justify-between"><p class="text-sm font-medium text-slate-500">{{ card.label }}</p><span class="h-2.5 w-2.5 rounded-full" :class="card.dot"></span></div>
                        <strong class="mt-3 block text-3xl font-bold tabular-nums text-slate-950">{{ card.value }}</strong>
                        <p class="mt-2 text-xs text-slate-400">{{ card.hint }}</p>
                    </article>
                </section>

                <section class="grid gap-5 xl:grid-cols-[1.35fr_.65fr]">
                    <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div><h2 class="text-base font-semibold text-slate-900">需求状态分布</h2><p class="mt-1 text-sm text-slate-500">快速查看当前工作量与交付进度。</p></div>
                        <div class="mt-7 space-y-5">
                            <div v-for="status in statusOrder" :key="status" class="grid grid-cols-[80px_minmax(0,1fr)_36px] items-center gap-3 text-sm">
                                <span class="text-slate-600">{{ statusLabels[status] }}</span>
                                <div class="h-2.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full transition-all" :style="{ width: `${summary.status_counts[status] / maxStatusCount * 100}%`, background: statusColors[status] }"></div></div>
                                <strong class="text-right tabular-nums text-slate-800">{{ summary.status_counts[status] }}</strong>
                            </div>
                        </div>
                    </article>
                    <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-base font-semibold text-slate-900">交付表现</h2>
                        <dl class="mt-5 divide-y divide-slate-100">
                            <div class="flex items-center justify-between py-4"><dt class="text-sm text-slate-500">按时交付率</dt><dd class="text-xl font-bold tabular-nums text-slate-950">{{ summary.on_time_rate === null ? '—' : `${summary.on_time_rate}%` }}</dd></div>
                            <div class="flex items-center justify-between py-4"><dt class="text-sm text-slate-500">平均交付周期</dt><dd class="text-xl font-bold tabular-nums text-slate-950">{{ summary.average_turnaround_days === null ? '—' : `${summary.average_turnaround_days} 天` }}</dd></div>
                            <div class="flex items-center justify-between py-4"><dt class="text-sm text-slate-500">累计改稿</dt><dd class="text-xl font-bold tabular-nums text-slate-950">{{ summary.revision_count }} 次</dd></div>
                            <div class="flex items-center justify-between py-4"><dt class="text-sm text-slate-500">待分配</dt><dd class="text-xl font-bold tabular-nums text-amber-600">{{ summary.pending }}</dd></div>
                        </dl>
                    </article>
                </section>
            </template>

            <template v-else>
                <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <form class="grid gap-3 border-b border-slate-100 p-5 md:grid-cols-2 xl:grid-cols-[minmax(240px,1fr)_170px_150px_170px_auto]" @submit.prevent="applyFilters">
                        <input v-model="filters.search" type="search" maxlength="100" placeholder="搜索编号或需求描述" class="h-10 rounded-lg border border-slate-200 px-3 text-sm outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100" />
                        <select v-model="filters.type" class="h-10 rounded-lg border border-slate-200 px-3 text-sm"><option value="">全部类型</option><option v-for="type in options.types" :key="type" :value="type">{{ typeLabels[type] }}</option></select>
                        <select v-model="filters.priority" class="h-10 rounded-lg border border-slate-200 px-3 text-sm"><option value="">全部优先级</option><option v-for="priority in options.priorities" :key="priority" :value="priority">{{ priorityLabels[priority] }}</option></select>
                        <select v-model="filters.status" class="h-10 rounded-lg border border-slate-200 px-3 text-sm"><option value="">全部状态</option><option v-for="status in options.statuses" :key="status" :value="status">{{ statusLabels[status] }}</option></select>
                        <div class="flex gap-2"><button type="submit" class="h-10 rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white">筛选</button><button type="button" class="h-10 rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-600" @click="resetFilters">重置</button></div>
                    </form>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[1320px] border-collapse text-left text-sm">
                            <thead class="bg-slate-50 text-slate-500"><tr><th class="px-4 py-3 font-medium">编号</th><th class="px-4 py-3 font-medium">品牌</th><th class="px-4 py-3 font-medium">需求类型</th><th class="px-4 py-3 font-medium">优先级</th><th class="px-4 py-3 font-medium">需求描述</th><th class="px-4 py-3 font-medium">提报部门</th><th class="px-4 py-3 font-medium">提报人</th><th class="px-4 py-3 font-medium">设计师</th><th class="px-4 py-3 font-medium">数量</th><th class="px-4 py-3 font-medium">提报日期</th><th class="px-4 py-3 font-medium">计划交付</th><th class="px-4 py-3 font-medium">实际交付</th><th class="px-4 py-3 font-medium">状态</th><th class="px-4 py-3 font-medium">延期</th><th class="px-4 py-3 font-medium">改稿</th><th v-if="canManage" class="px-4 py-3 text-right font-medium">操作</th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="row in requests.data" :key="row.uuid" class="transition hover:bg-slate-50/80">
                                    <td class="whitespace-nowrap px-4 py-3 font-semibold text-slate-700">{{ row.reference_no }}</td><td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ store.name }}</td>
                                    <td class="whitespace-nowrap px-4 py-3"><span class="rounded-md bg-blue-50 px-2 py-1 text-xs font-semibold text-blue-700">{{ typeLabels[row.request_type] }}</span></td>
                                    <td class="whitespace-nowrap px-4 py-3"><span class="font-semibold" :class="row.priority === 'urgent' ? 'text-rose-600' : row.priority === 'high' ? 'text-orange-600' : row.priority === 'medium' ? 'text-amber-600' : 'text-slate-500'">{{ priorityLabels[row.priority] }}</span></td>
                                    <td class="max-w-[320px] px-4 py-3"><p class="line-clamp-2 leading-5 text-slate-700" :title="row.description">{{ row.description }}</p></td>
                                    <td class="whitespace-nowrap px-4 py-3 text-slate-500">{{ row.requester_department || '—' }}</td><td class="whitespace-nowrap px-4 py-3 text-slate-700">{{ row.requester }}</td><td class="whitespace-nowrap px-4 py-3 text-slate-700">{{ row.designer || '待分配' }}</td>
                                    <td class="px-4 py-3 text-center tabular-nums">{{ row.quantity }}</td><td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-500">{{ row.requested_on }}</td><td class="whitespace-nowrap px-4 py-3 tabular-nums">{{ row.planned_delivery_date }}</td><td class="whitespace-nowrap px-4 py-3 tabular-nums">{{ row.actual_delivery_date || '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-3"><span class="rounded-md px-2 py-1 text-xs font-semibold" :style="{ color: statusColors[row.status], background: `${statusColors[row.status]}12` }">{{ statusLabels[row.status] }}</span></td>
                                    <td class="whitespace-nowrap px-4 py-3"><span v-if="row.is_delayed" class="font-semibold text-rose-600">{{ row.delay_days }} 天</span><span v-else class="text-emerald-600">正常</span></td><td class="px-4 py-3 text-center tabular-nums">{{ row.revision_count }}</td>
                                    <td v-if="canManage" class="whitespace-nowrap px-4 py-3 text-right"><button type="button" class="font-semibold text-blue-600 hover:text-blue-800" @click="openEdit(row)">处理</button></td>
                                </tr>
                                <tr v-if="!requests.data.length"><td :colspan="canManage ? 16 : 15" class="px-6 py-16 text-center"><p class="font-medium text-slate-500">暂无符合条件的需求</p><button v-if="canCreate && summary.total === 0" type="button" class="mt-3 text-sm font-semibold text-blue-600" @click="openCreate">提交第一条设计需求</button></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <footer v-if="requests.total" class="flex flex-col gap-3 border-t border-slate-100 px-5 py-4 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between">
                        <span>显示 {{ requests.from }}–{{ requests.to }} 条，共 {{ requests.total }} 条</span>
                        <nav class="flex flex-wrap gap-1" aria-label="需求分页"><button v-for="link in requests.links" :key="link.label" type="button" :disabled="!link.url" class="min-w-9 rounded-lg border px-3 py-2" :class="link.active ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-200 bg-white text-slate-600 disabled:opacity-40'" @click="go(link.url)">{{ paginationLabel(link.label) }}</button></nav>
                    </footer>
                </section>
            </template>
        </main>

        <Teleport to="body">
            <dialog :open="createOpen" class="fixed inset-0 z-50 m-0 h-full w-full max-w-none bg-slate-950/45 p-4" @click.self="createOpen = false" @keydown.esc.prevent="createOpen = false">
                <form class="mx-auto mt-[6vh] max-h-[88vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl sm:p-8" @submit.prevent="submitRequest">
                    <div class="flex items-start justify-between gap-4"><div><h2 class="text-xl font-bold text-slate-950">提报设计需求</h2><p class="mt-1 text-sm text-slate-500">说明需要交付的内容和期望日期。</p></div><button type="button" aria-label="关闭提报" class="text-2xl text-slate-400" @click="createOpen = false">×</button></div>
                    <div class="mt-6 grid gap-4 sm:grid-cols-2">
                        <label class="text-sm font-medium text-slate-700">需求类型<select v-model="createForm.request_type" class="mt-2 w-full rounded-lg border-slate-300"><option v-for="type in options.types" :key="type" :value="type">{{ typeLabels[type] }}</option></select></label>
                        <label class="text-sm font-medium text-slate-700">优先级<select v-model="createForm.priority" class="mt-2 w-full rounded-lg border-slate-300"><option v-for="priority in options.priorities" :key="priority" :value="priority">{{ priorityLabels[priority] }}</option></select></label>
                        <label class="text-sm font-medium text-slate-700">提报部门<input v-model="createForm.requester_department" maxlength="120" placeholder="如：品牌部" class="mt-2 w-full rounded-lg border-slate-300" /></label>
                        <label class="text-sm font-medium text-slate-700">数量<input v-model.number="createForm.quantity" type="number" min="1" max="999" class="mt-2 w-full rounded-lg border-slate-300" /></label>
                        <label class="text-sm font-medium text-slate-700 sm:col-span-2">计划交付日期<input v-model="createForm.planned_delivery_date" type="date" :min="today" required class="mt-2 w-full rounded-lg border-slate-300" /></label>
                        <label class="text-sm font-medium text-slate-700 sm:col-span-2">需求描述<textarea v-model="createForm.description" required maxlength="5000" rows="6" placeholder="说明尺寸、使用场景、文案、风格和交付格式等关键信息" class="mt-2 w-full rounded-lg border-slate-300"></textarea></label>
                    </div>
                    <p v-if="createForm.hasErrors" role="alert" class="mt-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ Object.values(createForm.errors).join('；') }}</p>
                    <div class="mt-6 flex justify-end gap-3"><button type="button" class="h-10 rounded-lg border border-slate-200 px-5 text-sm font-semibold" @click="createOpen = false">取消</button><button type="submit" :disabled="createForm.processing" class="h-10 rounded-lg bg-blue-600 px-5 text-sm font-semibold text-white disabled:opacity-50">{{ createForm.processing ? '提交中…' : '提交需求' }}</button></div>
                </form>
            </dialog>

            <dialog :open="editOpen" class="fixed inset-0 z-50 m-0 h-full w-full max-w-none bg-slate-950/45 p-4" @click.self="editOpen = false" @keydown.esc.prevent="editOpen = false">
                <form v-if="selected" class="mx-auto mt-[5vh] max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl sm:p-8" @submit.prevent="saveRequest">
                    <div class="flex items-start justify-between"><div><p class="text-xs font-semibold text-blue-600">{{ selected.reference_no }}</p><h2 class="mt-1 text-xl font-bold text-slate-950">处理设计需求</h2><p class="mt-2 text-sm leading-6 text-slate-500">{{ selected.description }}</p></div><button type="button" aria-label="关闭处理" class="text-2xl text-slate-400" @click="editOpen = false">×</button></div>
                    <div class="mt-6 grid gap-4 sm:grid-cols-2">
                        <label class="text-sm font-medium text-slate-700">设计师<select v-model="editForm.designer_id" class="mt-2 w-full rounded-lg border-slate-300"><option :value="null">待分配</option><option v-for="designer in options.designers" :key="designer.id" :value="designer.id">{{ designer.name }}</option></select></label>
                        <label class="text-sm font-medium text-slate-700">状态<select v-model="editForm.status" class="mt-2 w-full rounded-lg border-slate-300"><option v-for="status in options.statuses" :key="status" :value="status">{{ statusLabels[status] }}</option></select></label>
                        <label class="text-sm font-medium text-slate-700">计划交付日期<input v-model="editForm.planned_delivery_date" type="date" required class="mt-2 w-full rounded-lg border-slate-300" /></label>
                        <label class="text-sm font-medium text-slate-700">实际交付日期<input v-model="editForm.actual_delivery_date" type="date" class="mt-2 w-full rounded-lg border-slate-300" /></label>
                        <label class="text-sm font-medium text-slate-700">改稿次数<input v-model.number="editForm.revision_count" type="number" min="0" max="999" class="mt-2 w-full rounded-lg border-slate-300" /></label>
                        <label class="text-sm font-medium text-slate-700 sm:col-span-2">交付说明<textarea v-model="editForm.delivery_note" maxlength="3000" rows="4" placeholder="填写交付位置或需要提报人确认的事项" class="mt-2 w-full rounded-lg border-slate-300"></textarea></label>
                    </div>
                    <p v-if="editForm.hasErrors" role="alert" class="mt-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ Object.values(editForm.errors).join('；') }}</p>
                    <div class="mt-6 flex justify-end gap-3"><button type="button" class="h-10 rounded-lg border border-slate-200 px-5 text-sm font-semibold" @click="editOpen = false">取消</button><button type="submit" :disabled="editForm.processing" class="h-10 rounded-lg bg-blue-600 px-5 text-sm font-semibold text-white disabled:opacity-50">{{ editForm.processing ? '保存中…' : '保存更新' }}</button></div>
                </form>
            </dialog>
        </Teleport>
    </AppLayout>
</template>
