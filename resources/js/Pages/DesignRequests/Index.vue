<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';

type Attachment = { uuid: string; name: string; mime_type: string; size: number; url: string };
type ProgressLog = { uuid: string; action: 'accepted' | 'progress' | 'delivery_submitted' | 'revision_requested' | 'completed'; content: string | null; user: string; created_at: string };
type Row = {
    uuid: string; reference_no: string; task_name: string; request_type: string; priority: string; description: string;
    requester: string; requester_role: string; designer_id: number | null; designer: string | null;
    requested_on: string; submitted_at: string | null; planned_delivery_date: string; actual_delivery_date: string | null;
    accepted_at: string | null; delivery_submitted_at: string | null; reviewed_at: string | null; completed_at: string | null;
    status: string; revision_count: number; is_delayed: boolean; delay_days: number;
    can_accept: boolean; can_process: boolean; can_review: boolean;
    images: Attachment[]; progress_logs: ProgressLog[];
};
type PendingImage = { file: File; url: string };
type PageLink = { url: string | null; label: string; active: boolean };
type PageData = { data: Row[]; current_page: number; last_page: number; from: number | null; to: number | null; total: number; links: PageLink[] };
type DesignerPerformance = { designer_id: number; designer: string; total: number; in_progress: number; pending_review: number; completed: number; on_time_rate: number | null; average_revisions: number | null };
type Summary = { total: number; pending: number; in_progress: number; completed: number; overdue: number; on_time_rate: number | null; average_turnaround_days: number | null; status_counts: Record<string, number>; designer_performance: DesignerPerformance[] };

const props = defineProps<{
    scope: 'mine' | 'all'; today: string; canCreate: boolean; canManage: boolean; canViewOverview: boolean;
    filters: { tab: 'overview' | 'list'; status: string; priority: string; type: string; search: string };
    options: { types: string[]; priorities: string[]; statuses: string[] };
    summary: Summary; requests: PageData;
}>();

const typeLabels: Record<string, string> = { site_banner: '站内 Banner', edm: 'EDM', social_media: '社媒素材', product_detail: '产品详情', video: '视频', other: '其他' };
const priorityLabels: Record<string, string> = { urgent: '紧急', high: '高', medium: '中', low: '低' };
const statusLabels: Record<string, string> = { pending: '待分配', assigned: '已分配', in_progress: '设计中', review: '待确认', completed: '已完成', cancelled: '已取消' };
const statusOrder = ['pending', 'assigned', 'in_progress', 'review', 'completed', 'cancelled'];
const statusColors: Record<string, string> = { pending: '#f59e0b', assigned: '#6366f1', in_progress: '#2563eb', review: '#8b5cf6', completed: '#10b981', cancelled: '#94a3b8' };
const statusRowClasses: Record<string, string> = {
    pending: 'bg-amber-50/60 hover:bg-amber-50',
    assigned: 'bg-blue-50/55 hover:bg-blue-50',
    in_progress: 'bg-blue-50/55 hover:bg-blue-50',
    review: 'bg-blue-50/55 hover:bg-blue-50',
    completed: 'bg-emerald-50/60 hover:bg-emerald-50',
    cancelled: 'bg-slate-50/70 hover:bg-slate-100/70',
};
const statusActionClasses: Record<string, string> = {
    pending: 'bg-amber-50 group-hover:bg-amber-50',
    assigned: 'bg-blue-50 group-hover:bg-blue-50',
    in_progress: 'bg-blue-50 group-hover:bg-blue-50',
    review: 'bg-blue-50 group-hover:bg-blue-50',
    completed: 'bg-emerald-50 group-hover:bg-emerald-50',
    cancelled: 'bg-slate-50 group-hover:bg-slate-100',
};
const statusAccentClasses: Record<string, string> = {
    pending: 'border-l-amber-400',
    assigned: 'border-l-blue-400',
    in_progress: 'border-l-blue-500',
    review: 'border-l-indigo-400',
    completed: 'border-l-emerald-400',
    cancelled: 'border-l-slate-300',
};
const activeTab = ref(props.filters.tab);
const createOpen = ref(false);
const editOpen = ref(false);
const reviewOpen = ref(false);
const selected = ref<Row | null>(null);
const filters = ref({ status: props.filters.status, priority: props.filters.priority, type: props.filters.type, search: props.filters.search });
const createForm = useForm({ task_name: '', request_type: 'site_banner', priority: 'medium', description: '', planned_delivery_date: '', images: [] as File[] });
const editForm = useForm({ action: 'progress' as 'progress' | 'submit_delivery', progress_note: '' });
const reviewForm = useForm({ action: 'confirm' as 'confirm' | 'revision', review_note: '' });
const pendingImages = ref<PendingImage[]>([]);
const imageError = ref('');
const acceptingUuid = ref<string | null>(null);
const lightboxOpen = ref(false);
const lightboxImages = ref<Attachment[]>([]);
const lightboxIndex = ref(0);

const maxStatusCount = computed(() => Math.max(1, ...Object.values(props.summary.status_counts)));
const scopeLabel = computed(() => props.scope === 'all' ? '全部人员需求' : '我的需求');
const currentLightboxImage = computed(() => lightboxImages.value[lightboxIndex.value] ?? null);
const showActionColumn = computed(() => props.canManage || props.requests.data.some(row => row.can_review));
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
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            createOpen.value = false;
            clearPendingImages();
            createForm.reset();
            createForm.request_type = 'site_banner';
            createForm.priority = 'medium';
        },
    });
}
function openCreate(): void {
    createForm.clearErrors();
    imageError.value = '';
    createOpen.value = true;
}
function syncImages(): void { createForm.images = pendingImages.value.map(image => image.file); }
function addImages(files: File[]): void {
    imageError.value = '';
    for (const file of files) {
        if (!file.type.startsWith('image/')) continue;
        if (file.size > 8 * 1024 * 1024) {
            imageError.value = '单张图片不能超过 8MB。';
            continue;
        }
        if (pendingImages.value.length >= 10) {
            imageError.value = '每个任务最多粘贴 10 张图片。';
            break;
        }
        pendingImages.value.push({ file, url: URL.createObjectURL(file) });
    }
    syncImages();
}
function pasteDescription(event: ClipboardEvent): void {
    const files = Array.from(event.clipboardData?.items ?? [])
        .filter(item => item.kind === 'file' && item.type.startsWith('image/'))
        .map(item => item.getAsFile())
        .filter((file): file is File => file !== null);
    if (!files.length) return;
    event.preventDefault();
    addImages(files);
}
function selectImages(event: Event): void {
    const input = event.target as HTMLInputElement;
    addImages(Array.from(input.files ?? []));
    input.value = '';
}
function removeImage(index: number): void {
    const [removed] = pendingImages.value.splice(index, 1);
    if (removed) URL.revokeObjectURL(removed.url);
    syncImages();
}
function clearPendingImages(): void {
    pendingImages.value.forEach(image => URL.revokeObjectURL(image.url));
    pendingImages.value = [];
    syncImages();
}
function openLightbox(images: Attachment[], index = 0): void {
    if (!images.length) return;
    lightboxImages.value = images;
    lightboxIndex.value = Math.min(Math.max(index, 0), images.length - 1);
    lightboxOpen.value = true;
}
function closeLightbox(): void {
    lightboxOpen.value = false;
    lightboxImages.value = [];
    lightboxIndex.value = 0;
}
function moveLightbox(direction: number): void {
    const total = lightboxImages.value.length;
    if (total < 2) return;
    lightboxIndex.value = (lightboxIndex.value + direction + total) % total;
}
function handleLightboxKeydown(event: KeyboardEvent): void {
    if (!lightboxOpen.value) return;
    if (event.key === 'Escape') closeLightbox();
    if (event.key === 'ArrowLeft') moveLightbox(-1);
    if (event.key === 'ArrowRight') moveLightbox(1);
}
function openEdit(row: Row): void {
    if (!row.can_process) return;
    selected.value = row;
    editForm.clearErrors();
    editForm.action = 'progress';
    editForm.progress_note = '';
    editOpen.value = true;
}
function openReview(row: Row): void {
    if (!row.can_review) return;
    selected.value = row;
    reviewForm.clearErrors();
    reviewForm.action = 'confirm';
    reviewForm.review_note = '';
    reviewOpen.value = true;
}
function acceptRequest(row: Row): void {
    if (!row.can_accept || acceptingUuid.value) return;
    acceptingUuid.value = row.uuid;
    router.post(`/design-requests/${row.uuid}/accept`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            const acceptedRow = props.requests.data.find(item => item.uuid === row.uuid);
            if (acceptedRow?.can_process) openEdit(acceptedRow);
        },
        onFinish: () => { acceptingUuid.value = null; },
    });
}
function saveRequest(action: 'progress' | 'submit_delivery'): void {
    if (!selected.value) return;
    editForm.action = action;
    editForm.put(`/design-requests/${selected.value.uuid}`, { preserveScroll: true, onSuccess: () => { editOpen.value = false; selected.value = null; } });
}
function reviewRequest(action: 'confirm' | 'revision'): void {
    if (!selected.value) return;
    reviewForm.action = action;
    reviewForm.put(`/design-requests/${selected.value.uuid}/review`, {
        preserveScroll: true,
        onSuccess: () => { reviewOpen.value = false; selected.value = null; },
    });
}
function progressActionLabel(action: ProgressLog['action']): string {
    return {
        accepted: '接受任务', progress: '提交进展', delivery_submitted: '提交交付',
        revision_requested: '要求改稿', completed: '验收完成',
    }[action];
}
function statusLabel(status: string): string { return statusLabels[status] ?? '待定'; }
function statusColor(status: string): string { return statusColors[status] ?? '#64748b'; }
function paginationLabel(label: string): string {
    if (label.includes('Previous')) return '上一页';
    if (label.includes('Next')) return '下一页';
    return label.replace(/&laquo;|&raquo;/g, '').trim();
}
function latestProgress(row: Row): ProgressLog | null {
    for (let index = row.progress_logs.length - 1; index >= 0; index -= 1) {
        const log = row.progress_logs[index];
        if (log?.content?.trim()) return log;
    }
    return null;
}
function latestDelivery(row: Row): ProgressLog | null {
    for (let index = row.progress_logs.length - 1; index >= 0; index -= 1) {
        const log = row.progress_logs[index];
        if (log?.action === 'delivery_submitted') return log;
    }
    return null;
}
onMounted(() => window.addEventListener('keydown', handleLightboxKeydown));
onBeforeUnmount(() => {
    clearPendingImages();
    window.removeEventListener('keydown', handleLightboxKeydown);
});
</script>

<template>
    <Head title="设计需求" />
    <AppLayout :breadcrumbs="[{ label: '个人' }, { label: '设计需求' }]">
        <main class="mx-auto w-full max-w-[1680px] space-y-5">
            <header class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <div class="mb-2 flex items-center gap-2"><span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">{{ scopeLabel }}</span><span class="text-xs text-slate-400">个人工作区</span></div>
                    <h1 class="text-2xl font-bold tracking-tight text-slate-950">设计需求管理</h1>
                    <p class="mt-1.5 text-sm text-slate-500">提报、分配并跟进设计交付，切换店铺不会改变这里的数据。</p>
                </div>
                <button v-if="canCreate" type="button" class="h-11 rounded-xl bg-blue-600 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700" @click="openCreate">+ 提报新需求</button>
            </header>

            <nav class="flex gap-1 border-b border-slate-200" aria-label="设计需求视图">
                <button v-if="canViewOverview" type="button" class="border-b-2 px-4 py-3 text-sm font-semibold" :class="activeTab === 'overview' ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500'" @click="selectTab('overview')">效率总览</button>
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
                            <div class="flex items-center justify-between py-4"><dt class="text-sm text-slate-500">待分配</dt><dd class="text-xl font-bold tabular-nums text-amber-600">{{ summary.pending }}</dd></div>
                        </dl>
                    </article>
                </section>

                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <header class="border-b border-slate-100 px-6 py-5"><h2 class="text-base font-semibold text-slate-900">设计师交付概览</h2><p class="mt-1 text-sm text-slate-500">任务按实际接单设计师归属；只有提报人验收通过后才计入已完成。</p></header>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[860px] text-left text-sm">
                            <thead class="bg-slate-50/80 text-slate-500"><tr><th class="px-6 py-3.5 font-medium">设计师</th><th class="px-4 py-3.5 text-right font-medium">总任务</th><th class="px-4 py-3.5 text-right font-medium">处理中</th><th class="px-4 py-3.5 text-right font-medium">待验收</th><th class="px-4 py-3.5 text-right font-medium">已完成</th><th class="px-4 py-3.5 text-right font-medium">按时率</th><th class="px-6 py-3.5 text-right font-medium">平均改稿</th></tr></thead>
                            <tbody class="divide-y divide-slate-100"><tr v-for="item in summary.designer_performance" :key="item.designer_id"><td class="px-6 py-4 font-semibold text-slate-800">{{ item.designer }}</td><td class="px-4 py-4 text-right tabular-nums text-slate-600">{{ item.total }}</td><td class="px-4 py-4 text-right tabular-nums text-blue-600">{{ item.in_progress }}</td><td class="px-4 py-4 text-right tabular-nums text-violet-600">{{ item.pending_review }}</td><td class="px-4 py-4 text-right tabular-nums text-emerald-600">{{ item.completed }}</td><td class="px-4 py-4 text-right font-semibold tabular-nums text-slate-800">{{ item.on_time_rate === null ? '—' : `${item.on_time_rate}%` }}</td><td class="px-6 py-4 text-right tabular-nums text-slate-600">{{ item.average_revisions === null ? '—' : `${item.average_revisions} 次` }}</td></tr><tr v-if="!summary.designer_performance.length"><td colspan="7" class="px-6 py-10 text-center text-slate-400">暂无已接单任务</td></tr></tbody>
                        </table>
                    </div>
                </section>
            </template>

            <template v-else>
                <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <form class="grid gap-3 border-b border-slate-100 p-5 md:grid-cols-2 xl:grid-cols-[minmax(240px,1fr)_170px_150px_170px_auto]" @submit.prevent="applyFilters">
                        <input v-model="filters.search" type="search" maxlength="100" placeholder="搜索编号、任务名称或描述" class="h-10 rounded-lg border border-slate-200 px-3 text-sm outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100" />
                        <select v-model="filters.type" class="h-10 rounded-lg border border-slate-200 px-3 text-sm"><option value="">全部类型</option><option v-for="type in options.types" :key="type" :value="type">{{ typeLabels[type] }}</option></select>
                        <select v-model="filters.priority" class="h-10 rounded-lg border border-slate-200 px-3 text-sm"><option value="">全部优先级</option><option v-for="priority in options.priorities" :key="priority" :value="priority">{{ priorityLabels[priority] }}</option></select>
                        <select v-model="filters.status" class="h-10 rounded-lg border border-slate-200 px-3 text-sm"><option value="">全部状态</option><option v-for="status in options.statuses" :key="status" :value="status">{{ statusLabels[status] }}</option></select>
                        <div class="flex gap-2"><button type="submit" class="h-10 rounded-lg bg-slate-900 px-4 text-sm font-semibold text-white">筛选</button><button type="button" class="h-10 rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-600" @click="resetFilters">重置</button></div>
                    </form>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[1400px] border-collapse text-left text-sm">
                            <thead class="border-y border-slate-100 bg-slate-50/80 text-slate-500"><tr><th class="w-[170px] whitespace-nowrap px-5 py-3.5 font-medium">编号</th><th class="min-w-[280px] px-4 py-3.5 font-medium">任务信息</th><th class="w-[100px] whitespace-nowrap px-4 py-3.5 font-medium">附件</th><th class="min-w-[240px] px-4 py-3.5 font-medium">进度说明</th><th class="w-[130px] whitespace-nowrap px-4 py-3.5 font-medium">类型 / 优先级</th><th class="w-[170px] whitespace-nowrap px-4 py-3.5 font-medium">提交信息</th><th class="w-[110px] whitespace-nowrap px-4 py-3.5 font-medium">设计师</th><th class="w-[180px] whitespace-nowrap px-4 py-3.5 font-medium">时间</th><th class="w-[130px] whitespace-nowrap px-4 py-3.5 font-medium">状态</th><th v-if="showActionColumn" class="sticky right-0 z-10 w-[110px] whitespace-nowrap border-l border-slate-100 bg-slate-50 px-5 py-3.5 text-right font-medium">操作</th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="row in requests.data" :key="row.uuid" class="group align-top transition-colors" :class="statusRowClasses[row.status] || 'bg-white hover:bg-slate-50'">
                                    <td class="whitespace-nowrap border-l-4 px-5 py-5" :class="statusAccentClasses[row.status] || 'border-l-transparent'"><span class="font-mono text-sm font-semibold tracking-tight text-slate-700">{{ row.reference_no }}</span></td>
                                    <td class="px-4 py-5"><p class="line-clamp-1 font-semibold text-slate-900" :title="row.task_name">{{ row.task_name }}</p><p class="mt-1.5 line-clamp-2 max-w-[380px] text-sm leading-5 text-slate-500" :title="row.description">{{ row.description }}</p></td>
                                    <td class="px-4 py-5"><button v-if="row.images.length" type="button" class="relative inline-block h-12 w-16 overflow-visible text-left transition hover:scale-105 focus:outline-none focus:ring-4 focus:ring-blue-100" :aria-label="`预览 ${row.images.length} 个附件`" @click="openLightbox(row.images)"><img :src="row.images[0].url" :alt="row.images[0].name" class="h-12 w-16 rounded-lg border border-slate-200 object-cover shadow-sm" loading="lazy" /><span class="absolute -right-2 -top-2 grid min-h-6 min-w-6 place-items-center rounded-full border-2 border-white bg-slate-800 px-1 text-xs font-semibold text-white">{{ row.images.length }}</span></button><span v-else class="text-xs text-slate-400">无附件</span></td>
                                    <td class="px-4 py-5"><template v-if="latestProgress(row)"><p class="line-clamp-2 max-w-[280px] text-sm leading-5 text-slate-700" :title="latestProgress(row)?.content || ''">{{ latestProgress(row)?.content }}</p><p class="mt-2 text-xs text-slate-400">{{ latestProgress(row)?.user }} · {{ latestProgress(row)?.created_at }}</p></template><span v-else class="text-xs text-slate-400">暂无进展</span></td>
                                    <td class="px-4 py-5"><div class="flex flex-col items-start gap-2"><span class="whitespace-nowrap rounded-lg bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700">{{ typeLabels[row.request_type] }}</span><span class="whitespace-nowrap text-xs font-semibold" :class="row.priority === 'urgent' ? 'text-rose-600' : row.priority === 'high' ? 'text-orange-600' : row.priority === 'medium' ? 'text-amber-600' : 'text-slate-500'">{{ priorityLabels[row.priority] }}优先级</span></div></td>
                                    <td class="px-4 py-5"><p class="whitespace-nowrap font-medium text-slate-800">{{ row.requester }}</p><span class="mt-2 inline-flex max-w-[160px] truncate whitespace-nowrap rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600" :title="row.requester_role">{{ row.requester_role }}</span></td>
                                    <td class="whitespace-nowrap px-4 py-5"><span :class="row.designer ? 'text-slate-800' : 'text-amber-600'" class="font-medium">{{ row.designer || '待分配' }}</span></td>
                                    <td class="px-4 py-5 text-xs tabular-nums"><dl class="space-y-1.5"><div class="flex gap-2"><dt class="w-8 shrink-0 text-slate-400">提报</dt><dd class="whitespace-nowrap text-slate-600">{{ row.requested_on }}</dd></div><div class="flex gap-2"><dt class="w-8 shrink-0 text-slate-400">计划</dt><dd class="whitespace-nowrap font-medium text-slate-700">{{ row.planned_delivery_date }}</dd></div><div v-if="row.actual_delivery_date" class="flex gap-2"><dt class="w-8 shrink-0 text-slate-400">完成</dt><dd class="whitespace-nowrap text-emerald-600">{{ row.actual_delivery_date }}</dd></div></dl></td>
                                    <td class="px-4 py-5"><span class="inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold" :style="{ color: statusColor(row.status), background: `${statusColor(row.status)}12` }">{{ statusLabel(row.status) }}</span><p class="mt-2 whitespace-nowrap text-xs"><span v-if="row.is_delayed" class="font-semibold text-rose-600">延期 {{ row.delay_days }} 天</span><span v-else class="text-emerald-600">进度正常</span></p><p v-if="row.revision_count" class="mt-1 whitespace-nowrap text-xs text-slate-500">已改稿 {{ row.revision_count }} 次</p></td>
                                    <td v-if="showActionColumn" class="sticky right-0 border-l border-slate-100 px-5 py-5 text-right transition-colors" :class="statusActionClasses[row.status] || 'bg-white group-hover:bg-slate-50'"><button v-if="row.can_review" type="button" class="whitespace-nowrap rounded-lg border border-violet-200 bg-white/80 px-3 py-2 text-xs font-semibold text-violet-700 shadow-sm transition hover:bg-violet-100" @click="openReview(row)">验收</button><button v-else-if="row.can_accept" type="button" :disabled="acceptingUuid !== null" class="whitespace-nowrap rounded-lg border border-amber-300 bg-white/80 px-3 py-2 text-xs font-semibold text-amber-700 shadow-sm transition hover:bg-amber-100 disabled:cursor-wait disabled:opacity-60" @click="acceptRequest(row)">{{ acceptingUuid === row.uuid ? '接受中…' : '接受' }}</button><button v-else-if="row.can_process" type="button" class="whitespace-nowrap rounded-lg border border-blue-200 bg-white/80 px-3 py-2 text-xs font-semibold text-blue-700 shadow-sm transition hover:bg-blue-100" @click="openEdit(row)">处理</button><span v-else class="text-xs text-slate-300">—</span></td>
                                </tr>
                                <tr v-if="!requests.data.length"><td :colspan="showActionColumn ? 10 : 9" class="px-6 py-16 text-center"><p class="font-medium text-slate-500">暂无符合条件的需求</p><button v-if="canCreate && summary.total === 0" type="button" class="mt-3 text-sm font-semibold text-blue-600" @click="openCreate">提交第一条设计需求</button></td></tr>
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
                <form class="mx-auto mt-[3vh] flex max-h-[94vh] w-full max-w-3xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl" @submit.prevent="submitRequest">
                    <div class="flex items-start justify-between gap-4 border-b border-slate-100 px-6 py-5 sm:px-8"><div><h2 class="text-xl font-bold text-slate-950">提报设计需求</h2><p class="mt-1 text-sm text-slate-500">请完整填写任务内容，带 <span class="text-rose-500">*</span> 的项目为必填项。</p></div><button type="button" aria-label="关闭提报" class="grid h-9 w-9 shrink-0 place-items-center rounded-full text-2xl text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" @click="createOpen = false">×</button></div>
                    <div class="space-y-5 overflow-y-auto bg-slate-50/60 px-6 py-6 sm:px-8">
                        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="mb-4"><h3 class="text-base font-semibold text-slate-900">任务信息</h3><p class="mt-1 text-xs text-slate-500">名称应简短明确，方便后续查找和跟进。</p></div>
                            <label class="block text-sm font-semibold text-slate-700">任务名称 <span class="text-rose-500">*</span><input v-model="createForm.task_name" maxlength="160" required placeholder="例如：秋季活动首页 Banner 设计" class="mt-2 h-12 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-100" /></label>
                        </section>
                        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="mb-4"><h3 class="text-base font-semibold text-slate-900">任务安排</h3><p class="mt-1 text-xs text-slate-500">选择需求类型、优先级及期望交付时间。</p></div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <label class="block text-sm font-semibold text-slate-700">需求类型 <span class="text-rose-500">*</span><select v-model="createForm.request_type" class="mt-2 h-12 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-100"><option v-for="type in options.types" :key="type" :value="type">{{ typeLabels[type] }}</option></select></label>
                                <label class="block text-sm font-semibold text-slate-700">优先级 <span class="text-rose-500">*</span><select v-model="createForm.priority" class="mt-2 h-12 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-100"><option v-for="priority in options.priorities" :key="priority" :value="priority">{{ priorityLabels[priority] }}</option></select></label>
                                <label class="block text-sm font-semibold text-slate-700 sm:col-span-2">计划交付日期 <span class="text-rose-500">*</span><input v-model="createForm.planned_delivery_date" type="date" :min="today" required class="mt-2 h-12 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none transition focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-100" /></label>
                            </div>
                        </section>
                        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="mb-4"><h3 class="text-base font-semibold text-slate-900">任务内容</h3><p class="mt-1 text-xs text-slate-500">说明尺寸、文案、使用场景和交付格式，可直接粘贴参考图片。</p></div>
                            <label class="block text-sm font-semibold text-slate-700">任务描述 <span class="text-rose-500">*</span><textarea v-model="createForm.description" required maxlength="5000" rows="7" placeholder="请输入详细任务要求，也可以直接在这里粘贴截图或图片…" class="mt-2 w-full resize-y rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-6 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-100" @paste="pasteDescription"></textarea><span class="mt-2 block text-xs font-normal text-slate-400">支持 Ctrl/⌘ + V 粘贴图片，最多 10 张，单张不超过 8MB。</span></label>
                        </section>
                        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-center justify-between gap-3"><div><h3 class="text-base font-semibold text-slate-900">任务图片</h3><p class="mt-1 text-xs text-slate-500">可选，也可以直接粘贴到任务描述中。</p></div><label class="cursor-pointer rounded-xl border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-700 transition hover:bg-blue-100">选择图片<input type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple class="sr-only" @change="selectImages" /></label></div>
                            <div v-if="pendingImages.length" class="mt-4 grid grid-cols-3 gap-3 sm:grid-cols-5">
                                <figure v-for="(image, index) in pendingImages" :key="image.url" class="group relative overflow-hidden rounded-xl border border-slate-200 bg-slate-50"><img :src="image.url" :alt="image.file.name" class="aspect-square w-full object-cover" /><button type="button" class="absolute top-1.5 right-1.5 grid h-7 w-7 place-items-center rounded-full bg-slate-950/75 text-sm text-white" aria-label="移除图片" @click="removeImage(index)">×</button><figcaption class="truncate px-2 py-1.5 text-xs text-slate-500">{{ image.file.name || `粘贴图片 ${index + 1}` }}</figcaption></figure>
                            </div>
                            <p v-if="imageError" class="mt-2 text-sm text-rose-600">{{ imageError }}</p>
                        </section>
                        <p v-if="createForm.hasErrors" role="alert" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ Object.values(createForm.errors).join('；') }}</p>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-slate-100 bg-white px-6 py-4 sm:px-8"><button type="button" class="h-11 rounded-xl border border-slate-200 px-6 text-sm font-semibold text-slate-600 transition hover:bg-slate-50" @click="createOpen = false">取消</button><button type="submit" :disabled="createForm.processing" class="h-11 rounded-xl bg-blue-600 px-6 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-50">{{ createForm.processing ? '提交中…' : '提交需求' }}</button></div>
                </form>
            </dialog>

            <dialog :open="editOpen" class="fixed inset-0 z-50 m-0 h-full w-full max-w-none bg-slate-950/45 p-4" @click.self="editOpen = false" @keydown.esc.prevent="editOpen = false">
                <form v-if="selected" class="mx-auto mt-[5vh] flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl" @submit.prevent="saveRequest('progress')">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-100 px-6 py-5 sm:px-8"><div class="min-w-0"><p class="text-xs font-semibold text-blue-600">{{ selected.reference_no }}</p><h2 class="mt-1 truncate text-xl font-bold text-slate-950">{{ selected.task_name }}</h2><div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-500"><span>负责人：<strong class="font-medium text-slate-700">{{ selected.designer }}</strong></span><span>计划交付：<strong class="font-medium text-slate-700">{{ selected.planned_delivery_date }}</strong></span></div></div><button type="button" aria-label="关闭处理" class="grid h-9 w-9 shrink-0 place-items-center rounded-full text-2xl text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" @click="editOpen = false">×</button></header>
                    <div class="min-h-0 flex-1 space-y-5 overflow-y-auto bg-slate-50/60 px-6 py-6 sm:px-8">
                        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h3 class="text-sm font-semibold text-slate-900">任务描述</h3><p class="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-600">{{ selected.description }}</p></section>
                        <section v-if="selected.images.length" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-center justify-between">
                                <h3 class="text-sm font-semibold text-slate-900">任务附件</h3>
                                <button type="button" class="text-sm font-semibold text-blue-600" @click="openLightbox(selected.images)">查看全部 {{ selected.images.length }} 张</button>
                            </div>
                            <div class="mt-3 flex gap-2 overflow-x-auto">
                                <button v-for="(image, index) in selected.images" :key="image.uuid" type="button" class="h-20 w-24 shrink-0 overflow-hidden rounded-xl border border-slate-200" @click="openLightbox(selected.images, index)">
                                    <img :src="image.url" :alt="image.name" class="h-full w-full object-cover" />
                                </button>
                            </div>
                        </section>
                        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-center justify-between"><div><h3 class="text-base font-semibold text-slate-900">进展记录</h3><p class="mt-1 text-xs text-slate-500">进展、交付和改稿都会保留；验收通过后任务结束。</p></div><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-500">{{ selected.progress_logs.length }} 条</span></div>
                            <ol v-if="selected.progress_logs.length" class="mt-5 space-y-4 border-l-2 border-slate-100 pl-5">
                                <li v-for="log in selected.progress_logs" :key="log.uuid" class="relative"><span class="absolute -left-[27px] top-1 h-3 w-3 rounded-full border-2 border-white" :class="log.action === 'completed' ? 'bg-emerald-500' : log.action === 'revision_requested' ? 'bg-rose-500' : log.action === 'delivery_submitted' ? 'bg-violet-500' : log.action === 'accepted' ? 'bg-blue-500' : 'bg-indigo-500'"></span><div class="flex flex-wrap items-center gap-2"><strong class="text-sm font-semibold text-slate-800">{{ log.user }}</strong><span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="log.action === 'completed' ? 'bg-emerald-50 text-emerald-700' : log.action === 'revision_requested' ? 'bg-rose-50 text-rose-700' : log.action === 'delivery_submitted' ? 'bg-violet-50 text-violet-700' : log.action === 'accepted' ? 'bg-blue-50 text-blue-700' : 'bg-indigo-50 text-indigo-700'">{{ progressActionLabel(log.action) }}</span><time class="text-xs text-slate-400">{{ log.created_at }}</time></div><p v-if="log.content" class="mt-1.5 whitespace-pre-wrap text-sm leading-6 text-slate-600">{{ log.content }}</p></li>
                            </ol>
                            <p v-else class="mt-5 rounded-xl bg-slate-50 px-4 py-5 text-center text-sm text-slate-400">暂无进展记录</p>
                        </section>
                        <label class="block rounded-2xl border border-slate-200 bg-white p-5 text-sm font-semibold text-slate-700 shadow-sm">进展或交付说明 <span class="text-rose-500">*</span><textarea v-model="editForm.progress_note" required maxlength="3000" rows="5" placeholder="填写完成情况、交付链接或需要沟通的问题…" class="mt-2 w-full resize-y rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-normal leading-6 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-100"></textarea><span class="mt-2 block text-right text-xs font-normal text-slate-400">{{ editForm.progress_note.length }} / 3000</span></label>
                        <p v-if="editForm.hasErrors" role="alert" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ Object.values(editForm.errors).join('；') }}</p>
                    </div>
                    <footer class="flex flex-col-reverse gap-3 border-t border-slate-100 bg-white px-6 py-4 sm:flex-row sm:justify-end sm:px-8"><button type="button" class="h-11 rounded-xl border border-slate-200 px-5 text-sm font-semibold text-slate-600 transition hover:bg-slate-50" @click="editOpen = false">取消</button><button type="submit" :disabled="editForm.processing" class="h-11 rounded-xl border border-blue-200 bg-blue-50 px-5 text-sm font-semibold text-blue-700 transition hover:bg-blue-100 disabled:opacity-50">提交进展</button><button type="button" :disabled="editForm.processing" class="h-11 rounded-xl bg-violet-600 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-700 disabled:opacity-50" @click="saveRequest('submit_delivery')">提交交付</button></footer>
                </form>
            </dialog>

            <dialog :open="reviewOpen" class="fixed inset-0 z-50 m-0 h-full w-full max-w-none bg-slate-950/45 p-4" @click.self="reviewOpen = false" @keydown.esc.prevent="reviewOpen = false">
                <form v-if="selected" class="mx-auto mt-[7vh] flex max-h-[86vh] w-full max-w-2xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl" @submit.prevent="reviewRequest('confirm')">
                    <header class="flex items-start justify-between gap-4 border-b border-slate-100 px-6 py-5 sm:px-8"><div class="min-w-0"><p class="text-xs font-semibold text-violet-600">{{ selected.reference_no }} · 待验收</p><h2 class="mt-1 truncate text-xl font-bold text-slate-950">{{ selected.task_name }}</h2><p class="mt-2 text-sm text-slate-500">设计师：<strong class="font-medium text-slate-700">{{ selected.designer }}</strong><span v-if="selected.delivery_submitted_at"> · 提交交付 {{ selected.delivery_submitted_at }}</span></p></div><button type="button" aria-label="关闭验收" class="grid h-9 w-9 shrink-0 place-items-center rounded-full text-2xl text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" @click="reviewOpen = false">×</button></header>
                    <div class="min-h-0 flex-1 space-y-5 overflow-y-auto bg-slate-50/60 px-6 py-6 sm:px-8">
                        <section class="rounded-2xl border border-violet-100 bg-violet-50/70 p-5"><div class="flex items-center justify-between gap-3"><h3 class="text-sm font-semibold text-violet-950">本次交付说明</h3><span class="rounded-full bg-white px-2.5 py-1 text-xs font-medium text-violet-700">已改稿 {{ selected.revision_count }} 次</span></div><p class="mt-3 whitespace-pre-wrap text-sm leading-6 text-violet-900">{{ latestDelivery(selected)?.content || '设计师未填写交付说明' }}</p></section>
                        <section v-if="selected.images.length" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><h3 class="text-sm font-semibold text-slate-900">任务附件</h3><button type="button" class="text-sm font-semibold text-blue-600" @click="openLightbox(selected.images)">查看全部 {{ selected.images.length }} 张</button></div><div class="mt-3 flex gap-2 overflow-x-auto"><button v-for="(image, index) in selected.images" :key="image.uuid" type="button" class="h-20 w-24 shrink-0 overflow-hidden rounded-xl border border-slate-200" @click="openLightbox(selected.images, index)"><img :src="image.url" :alt="image.name" class="h-full w-full object-cover" /></button></div></section>
                        <label class="block rounded-2xl border border-slate-200 bg-white p-5 text-sm font-semibold text-slate-700 shadow-sm">验收说明<textarea v-model="reviewForm.review_note" maxlength="3000" rows="5" placeholder="确认完成时可选；要求改稿时请说明需要调整的内容…" class="mt-2 w-full resize-y rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-normal leading-6 outline-none transition placeholder:text-slate-400 focus:border-violet-500 focus:bg-white focus:ring-4 focus:ring-violet-100"></textarea><span class="mt-2 block text-right text-xs font-normal text-slate-400">{{ reviewForm.review_note.length }} / 3000</span></label>
                        <p v-if="reviewForm.hasErrors" role="alert" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ Object.values(reviewForm.errors).join('；') }}</p>
                    </div>
                    <footer class="flex flex-col-reverse gap-3 border-t border-slate-100 bg-white px-6 py-4 sm:flex-row sm:justify-end sm:px-8"><button type="button" class="h-11 rounded-xl border border-slate-200 px-5 text-sm font-semibold text-slate-600 transition hover:bg-slate-50" @click="reviewOpen = false">稍后验收</button><button type="button" :disabled="reviewForm.processing" class="h-11 rounded-xl border border-rose-200 bg-rose-50 px-5 text-sm font-semibold text-rose-700 transition hover:bg-rose-100 disabled:opacity-50" @click="reviewRequest('revision')">要求改稿</button><button type="submit" :disabled="reviewForm.processing" class="h-11 rounded-xl bg-emerald-600 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700 disabled:opacity-50">确认完成</button></footer>
                </form>
            </dialog>

            <dialog :open="lightboxOpen" class="fixed inset-0 z-[70] m-0 h-full w-full max-w-none bg-slate-950/90 p-0 text-white" aria-label="图片预览" @click.self="closeLightbox">
                <div v-if="currentLightboxImage" class="flex h-full w-full flex-col" @click.self="closeLightbox">
                    <header class="flex h-16 shrink-0 items-center justify-between border-b border-white/10 px-5 sm:px-8">
                        <div class="min-w-0"><p class="truncate text-sm font-medium text-white">{{ currentLightboxImage.name || `附件 ${lightboxIndex + 1}` }}</p><p class="mt-0.5 text-xs text-slate-400">{{ lightboxIndex + 1 }} / {{ lightboxImages.length }}</p></div>
                        <button type="button" aria-label="关闭图片预览" class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-white/10 text-2xl text-white transition hover:bg-white/20" @click="closeLightbox">×</button>
                    </header>
                    <div class="relative flex min-h-0 flex-1 items-center justify-center px-16 py-5 sm:px-24" @click.self="closeLightbox">
                        <button v-if="lightboxImages.length > 1" type="button" aria-label="上一张图片" class="absolute left-3 top-1/2 grid h-12 w-12 -translate-y-1/2 place-items-center rounded-full bg-white/10 text-3xl text-white backdrop-blur transition hover:bg-white/20 sm:left-8" @click="moveLightbox(-1)">‹</button>
                        <img :src="currentLightboxImage.url" :alt="currentLightboxImage.name" class="max-h-full max-w-full select-none object-contain drop-shadow-2xl" />
                        <button v-if="lightboxImages.length > 1" type="button" aria-label="下一张图片" class="absolute right-3 top-1/2 grid h-12 w-12 -translate-y-1/2 place-items-center rounded-full bg-white/10 text-3xl text-white backdrop-blur transition hover:bg-white/20 sm:right-8" @click="moveLightbox(1)">›</button>
                    </div>
                    <footer v-if="lightboxImages.length > 1" class="flex h-24 shrink-0 items-center justify-center gap-2 overflow-x-auto border-t border-white/10 px-5 py-3">
                        <button v-for="(image, index) in lightboxImages" :key="image.uuid" type="button" class="h-16 w-20 shrink-0 overflow-hidden rounded-lg border-2 transition" :class="index === lightboxIndex ? 'border-blue-400 opacity-100' : 'border-transparent opacity-55 hover:opacity-90'" :aria-label="`查看第 ${index + 1} 张图片`" @click="lightboxIndex = index"><img :src="image.url" :alt="image.name" class="h-full w-full object-cover" loading="lazy" /></button>
                    </footer>
                </div>
            </dialog>
        </Teleport>
    </AppLayout>
</template>
