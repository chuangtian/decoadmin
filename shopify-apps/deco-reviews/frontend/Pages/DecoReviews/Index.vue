<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppLayout from '../../../../../resources/js/Layouts/AppLayout.vue';
import ReviewFormEditor from '../../Components/ReviewFormEditor.vue';

type Tab = 'overview' | 'reviews' | 'invitations' | 'form' | 'settings' | 'imports' | 'widgets';
type ReviewStatus = 'pending' | 'published' | 'unpublished';
type ReviewKind = 'product' | 'store';
type Media = { uuid: string; type: string; url: string };
type Review = {
    uuid: string; kind: ReviewKind; product_id: number | null; product_title: string | null;
    author_name: string; author_email: string | null; rating: number; title: string | null;
    body: string; status: ReviewStatus; featured: boolean; verified_source: 'none' | 'order' | 'import' | 'manual';
    incentivized: boolean; reply: string | null; created_at: string; published_at: string | null; media: Media[];
    form_answers?: Array<{ question_id: string; label: string; type: string; value: string | string[]; public: boolean }>;
};
type Invitation = { uuid: string; order_id: number; order_number?: string | null; product_title?: string | null; status?: string; created_at?: string; due_at?: string | null; sent_at?: string | null; reminder_sent_at?: string | null; completed_at?: string | null; error_code?: string | null };
type ImportError = { line: number; code: string };
type ImportRow = { uuid: string; status?: string; imported?: number; skipped?: number; errors?: ImportError[] | null; created_at?: string; undone_at?: string | null; can_undo?: boolean };
type PageData<T> = { data: T[]; current_page: number; last_page: number; total: number };
type Settings = {
    enabled: boolean; auto_publish_days: number | null; invites_enabled: boolean; domestic_delay_days: number;
    international_delay_days: number; auto_invites_enabled: boolean; reminders_enabled: boolean; reminder_days: number;
    reminder_subject: string; reminder_body: string; email_button_label: string; email_accent: string; auto_invites_since: string | null;
    star_color: string; corner_style: 'rounded' | 'square';
    display_name: 'full' | 'initials'; show_verified: boolean; show_incentive: boolean; layout: 'grid' | 'list' | 'mosaic';
    page_size: 6 | 12 | 24; heading: string; reply_to: string; subject: string; email_body: string; marketing_only: boolean;
};
type FormConfig = {
    version: string; heading: string; description: string; name_label: string; title_label: string; body_label: string; submit_label: string;
    thank_you: string; allow_photos: boolean; allow_video: boolean;
    questions: Array<{ id: string; label: string; type: 'single' | 'multiple' | 'scale'; required: boolean; public: boolean; kind: 'all' | 'product' | 'store'; product_ids: number[]; options: string[]; min: number; max: number }>;
};

const props = defineProps<{
    organization: { id: number; name: string }; store: { id: number; name: string }; canManage: boolean; baseUrl: string;
    tab: Tab; filters: Record<string, string | number | null | undefined>; reviews: PageData<Review>; invitations: PageData<Invitation>;
    stats: { total: number; published: number; pending: number; average: number; media: number; invites_sent: number };
    products: Array<{ id: number; title: string }>; settings: Settings; imports: ImportRow[]; formConfig: FormConfig;
}>();

const tabs: Array<{ key: Tab; label: string }> = [
    { key: 'overview', label: '概览' }, { key: 'reviews', label: '评价' }, { key: 'invitations', label: '邀请' }, { key: 'form', label: '表单' },
    { key: 'settings', label: '设置' }, { key: 'imports', label: '导入' }, { key: 'widgets', label: '组件' },
];
const page = usePage<{ flash?: { success?: string; error?: string }; errors?: Record<string, string> }>();
const notice = ref('');
const formEditorDirty = ref(false);
const query = ref({
    kind: String(props.filters.kind || 'product'), status: String(props.filters.status || ''), rating: String(props.filters.rating || ''),
    q: String(props.filters.q || ''), sort: String(props.filters.sort || 'newest'),
});
const selected = ref<string[]>([]);
const bulkForm = useForm({ ids: [] as string[], status: 'published' as ReviewStatus, reason: '' });
const activeMedia = ref<{ review: Review; index: number } | null>(null);
const editingReview = ref<Review | null>(null);
const createDialog = ref<HTMLElement | null>(null);
const editDialog = ref<HTMLElement | null>(null);
const mediaDialog = ref<HTMLElement | null>(null);
let focusBeforeDialog: HTMLElement | null = null;
const editForm = useForm({ status: 'pending' as ReviewStatus, featured: false, reply: '', reason: '' });
const createOpen = ref(false);
const createForm = useForm({
    kind: 'product' as ReviewKind, product_id: null as number | null, author_name: '', author_email: '', rating: 5,
    title: '', body: '', media: [] as File[],
});
const inviteForm = useForm({ order_id: null as number | null });
const importForm = useForm({ file: null as File | null });
const settingsForm = useForm<Settings>({ ...props.settings });
const widgetModes = [
    { key: 'reviews', name: '评价墙', description: '完整评价列表与筛选。', visual: 'grid grid-cols-2 gap-2' },
    { key: 'stars', name: '星级摘要', description: '平均分与评分分布。', visual: 'flex items-center justify-center' },
    { key: 'carousel', name: '评价轮播', description: '逐条展示精选评价。', visual: 'flex items-center' },
    { key: 'trust', name: '信任徽章', description: '紧凑显示评分与评价数。', visual: 'flex items-center justify-center' },
    { key: 'snippets', name: '评价摘录', description: '适合商品信息附近的短评。', visual: 'space-y-2' },
    { key: 'gallery', name: '媒体画廊', description: '突出买家图片内容。', visual: 'grid grid-cols-3 gap-1' },
    { key: 'video', name: '视频评价', description: '聚焦单条视频评价。', visual: 'flex items-center justify-center' },
    { key: 'sidebar', name: '侧边评价', description: '侧栏形式的评价入口。', visual: 'flex justify-end' },
    { key: 'floating', name: '悬浮评分', description: '页面边缘的紧凑入口。', visual: 'flex items-end justify-end' },
];

const stars = (rating: number) => '★★★★★'.slice(0, Math.max(0, Math.min(5, rating)));
const formatDate = (value?: string | null) => value ? new Intl.DateTimeFormat('zh-CN', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—';
const statusLabel = (status: string) => ({ pending: '待审核', published: '已发布', unpublished: '未发布', verification_required: '待验证履约与发送条件', scheduled: '已排期', sent: '已发送', sending_reminder: '正在发送提醒', reminder_held: '提醒发送结果待复核', unsubscribed: '已退订', cancelled: '已取消', completed: '已完成', processing: '处理中', failed: '失败', undone: '已撤销' }[status] || status);
const statusClass = (status: string) => ({ published: 'bg-emerald-50 text-emerald-700', pending: 'bg-amber-50 text-amber-700', reminder_held: 'bg-amber-50 text-amber-800', unpublished: 'bg-slate-100 text-slate-700', failed: 'bg-red-50 text-red-700', cancelled: 'bg-slate-100 text-slate-600' }[status] || 'bg-blue-50 text-blue-700');
const exportUrl = computed(() => {
    const params = new URLSearchParams();
    Object.entries(query.value).forEach(([key, value]) => { if (value) params.set(key, value); });
    return `${props.baseUrl}/export${params.size ? `?${params}` : ''}`;
});

const navigate = (tab: Tab, extra: Record<string, unknown> = {}) => {
    router.get(props.baseUrl, { tab, ...extra }, { preserveState: true, preserveScroll: true, replace: true });
};
const applyFilters = () => navigate('reviews', query.value);
const goPage = (page: number, area: 'reviews' | 'invitations') => navigate(area, area === 'reviews' ? { ...query.value, page } : { page });
const toggleAll = (checked: boolean) => { selected.value = checked ? props.reviews.data.map(review => review.uuid) : []; };

const focusable = (root: HTMLElement) => Array.from(root.querySelectorAll<HTMLElement>('button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')).filter(node => !node.hasAttribute('hidden'));
const openDialog = async (target: typeof createDialog | typeof editDialog | typeof mediaDialog) => {
    focusBeforeDialog = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    await nextTick();
    if (target.value) (focusable(target.value)[0] || target.value).focus();
};
const closeDialog = (kind: 'create' | 'edit' | 'media') => {
    if (kind === 'create') createOpen.value = false;
    if (kind === 'edit') editingReview.value = null;
    if (kind === 'media') activeMedia.value = null;
    nextTick(() => focusBeforeDialog?.focus());
};
const trapDialog = (event: KeyboardEvent, root: HTMLElement | null, close: () => void) => {
    if (event.key === 'Escape') { event.preventDefault(); close(); return; }
    if (event.key !== 'Tab' || !root) return;
    const nodes = focusable(root); if (!nodes.length) return;
    const first = nodes[0]; const last = nodes[nodes.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
};
const openCreate = async () => {
    focusBeforeDialog = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    createOpen.value = true; await nextTick();
    createDialog.value = document.querySelector<HTMLElement>('[aria-labelledby="create-review-title"]');
    (createDialog.value && (focusable(createDialog.value)[0] || createDialog.value))?.focus();
};

const openEditor = (review: Review) => {
    editingReview.value = review;
    editForm.status = review.status; editForm.featured = review.featured; editForm.reply = review.reply || ''; editForm.reason = '';
    editForm.clearErrors();
    focusBeforeDialog = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    nextTick(() => {
        editDialog.value = document.querySelector<HTMLElement>('[aria-labelledby="edit-review-title"]');
        if (editDialog.value) (focusable(editDialog.value)[0] || editDialog.value).focus();
    });
};
const saveReview = () => {
    if (!editingReview.value) return;
    editForm.patch(`${props.baseUrl}/reviews/${editingReview.value.uuid}`, {
        preserveScroll: true, onSuccess: () => { closeDialog('edit'); notice.value = '评价已更新。'; },
    });
};
const submitBulk = () => {
    bulkForm.ids = [...selected.value];
    bulkForm.post(`${props.baseUrl}/reviews/bulk`, { preserveScroll: true, onSuccess: () => { selected.value = []; bulkForm.reset(); notice.value = '批量操作已完成。'; } });
};
const submitReview = () => createForm.post(`${props.baseUrl}/reviews`, {
    forceFormData: true, preserveScroll: true,
    onSuccess: () => { createForm.reset(); createForm.kind = 'product'; createForm.rating = 5; closeDialog('create'); notice.value = '评价已创建并进入审核流程。'; },
});
const scheduleInvitation = () => inviteForm.post(`${props.baseUrl}/invitations`, {
    preserveScroll: true, onSuccess: () => { inviteForm.reset(); notice.value = '邀评记录已建立；验证履约与发送条件后才能排期。'; },
});
const cancelInvitation = (invitation: Invitation) => router.post(`${props.baseUrl}/invitations/${invitation.uuid}/cancel`, {}, { preserveScroll: true, onSuccess: () => { notice.value = '邀请已取消。'; }, onError: () => { notice.value = ''; } });
const saveSettings = () => settingsForm.transform(data => {
    const { auto_invites_since: _readOnlyActivation, ...payload } = data;
    return payload;
}).put(`${props.baseUrl}/settings`, { preserveScroll: true, onSuccess: () => { settingsForm.auto_invites_since = props.settings.auto_invites_since; notice.value = '设置已保存。'; } });
const submitImport = () => importForm.post(`${props.baseUrl}/imports`, {
    forceFormData: true, preserveScroll: true, onSuccess: () => { importForm.reset(); notice.value = 'CSV 已提交，重复评价会被安全跳过。'; },
});
const undoImport = (row: ImportRow) => router.post(`${props.baseUrl}/imports/${row.uuid}/undo`, {}, { preserveScroll: true, onSuccess: () => { notice.value = '本次导入已撤销。'; }, onError: () => { notice.value = ''; } });
const showMedia = async (review: Review, index: number) => {
    focusBeforeDialog = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    activeMedia.value = { review, index }; await nextTick();
    mediaDialog.value = document.getElementById('review-media-dialog');
    mediaDialog.value?.focus();
};
const moveMedia = (step: number) => { if (!activeMedia.value) return; const count = activeMedia.value.review.media.length; activeMedia.value.index = (activeMedia.value.index + step + count) % count; };
const onMediaKey = (event: KeyboardEvent) => { event.stopPropagation(); trapDialog(event, mediaDialog.value, () => closeDialog('media')); if (event.key === 'ArrowLeft') moveMedia(-1); if (event.key === 'ArrowRight') moveMedia(1); };
const onDialogKey = onMediaKey;
const globalDialogKey = (event: KeyboardEvent) => {
    if (activeMedia.value) return onMediaKey(event);
    if (editingReview.value) return trapDialog(event, editDialog.value, () => closeDialog('edit'));
    if (createOpen.value) return trapDialog(event, createDialog.value, () => closeDialog('create'));
};
onMounted(() => document.addEventListener('keydown', globalDialogKey));
onBeforeUnmount(() => document.removeEventListener('keydown', globalDialogKey));
watch([createOpen, editingReview, activeMedia], ([creating, editing, media], previous) => {
    if (!creating && !editing && !media && previous.some(Boolean)) nextTick(() => focusBeforeDialog?.focus());
});
watch(() => props.settings, value => settingsForm.defaults({ ...value }), { deep: true });
watch(() => [props.filters, props.reviews.current_page] as const, ([filters]) => {
    query.value = { kind: String(filters.kind || 'product'), status: String(filters.status || ''), rating: String(filters.rating || ''), q: String(filters.q || ''), sort: String(filters.sort || 'newest') };
    selected.value = [];
    bulkForm.clearErrors();
}, { deep: true });
</script>

<template>
    <Head title="Deco Reviews" />
    <AppLayout :breadcrumbs="[{ label: '工作台', href: '/dashboard' }, { label: '应用中心' }, { label: 'Deco Reviews' }]">
        <div class="mx-auto max-w-7xl space-y-6 text-[14px] text-slate-800">
            <header class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="font-semibold text-violet-700">{{ store.name }}</p>
                    <h1 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">Deco Reviews</h1>
                    <p class="mt-2 text-slate-500">集中管理商品评价、店铺评价与购买后邀请。</p>
                </div>
                <button v-if="canManage && tab === 'reviews'" type="button" class="rounded-xl bg-violet-700 px-5 py-3 font-semibold text-white hover:bg-violet-800" @click="openCreate">添加评价</button>
            </header>

            <nav aria-label="Deco Reviews 导航" class="overflow-x-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-sm">
                <div class="flex min-w-max gap-1">
                    <button v-for="item in tabs" :key="item.key" type="button" class="rounded-xl px-4 py-2.5 font-semibold transition" :class="tab === item.key ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-100'" :aria-current="tab === item.key ? 'page' : undefined" @click="navigate(item.key)">{{ item.label }}</button>
                </div>
            </nav>

            <p v-if="notice" role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800">{{ notice }}</p>
            <div v-if="page.props.flash?.error || Object.keys(page.props.errors || {}).length" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-800">
                <p v-if="page.props.flash?.error">{{ page.props.flash.error }}</p>
                <p v-for="(error, field) in page.props.errors" :key="field">{{ error }}</p>
            </div>

            <template v-if="tab === 'overview'">
                <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-slate-500">全部评价</p><p class="mt-2 text-3xl font-semibold text-slate-950">{{ stats.total }}</p><p class="mt-2 text-slate-500">{{ stats.published }} 条已发布</p></article>
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-slate-500">平均评分</p><p class="mt-2 text-3xl font-semibold text-slate-950">{{ Number(stats.average || 0).toFixed(1) }}</p><p class="mt-2 tracking-wider text-amber-500">{{ stars(Math.round(stats.average || 0)) }}</p></article>
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-slate-500">待审核</p><p class="mt-2 text-3xl font-semibold text-slate-950">{{ stats.pending }}</p><button class="mt-2 font-semibold text-violet-700" type="button" @click="navigate('reviews', { status: 'pending' })">前往审核</button></article>
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-slate-500">已发送邀请</p><p class="mt-2 text-3xl font-semibold text-slate-950">{{ stats.invites_sent }}</p><p class="mt-2 text-slate-500">{{ stats.media }} 条评价含图片或视频</p></article>
                </section>
                <section class="grid gap-5 lg:grid-cols-3">
                    <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
                        <h2 class="text-lg font-semibold text-slate-950">评价运营</h2><p class="mt-2 text-slate-500">审核新评价、公开商家回复，并将优质内容设为精选。</p>
                        <div class="mt-5 grid gap-3 sm:grid-cols-3"><button class="rounded-xl bg-slate-950 px-4 py-3 font-semibold text-white" @click="navigate('reviews', { status: 'pending' })">审核待处理评价</button><button class="rounded-xl border border-slate-200 px-4 py-3 font-semibold" @click="navigate('invitations')">创建邀请</button><button class="rounded-xl border border-slate-200 px-4 py-3 font-semibold" @click="navigate('widgets')">预览展示组件</button></div>
                    </article>
                    <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-semibold text-slate-950">展示状态</h2><p class="mt-4 inline-flex rounded-full px-3 py-1.5 font-semibold" :class="settings.enabled ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'">{{ settings.enabled ? '已启用' : '未启用' }}</p><p class="mt-4 text-slate-500">公开预览仅返回已批准且经过脱敏的数据，不包含评价者邮箱。</p></article>
                </section>
            </template>

            <template v-else-if="tab === 'reviews'">
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex gap-2 border-b border-slate-200 pb-4"><button v-for="kind in [{ value: 'product', label: '商品评价' }, { value: 'store', label: '店铺评价' }]" :key="kind.value" type="button" class="rounded-lg px-4 py-2 font-semibold" :class="query.kind === kind.value ? 'bg-violet-50 text-violet-800' : 'text-slate-600'" @click="query.kind = kind.value; applyFilters()">{{ kind.label }}</button></div>
                    <form class="mt-4 grid gap-3 md:grid-cols-5" @submit.prevent="applyFilters">
                        <label class="md:col-span-2"><span class="sr-only">搜索评价</span><input v-model="query.q" type="search" placeholder="搜索作者、标题或内容" class="w-full rounded-xl border border-slate-200 px-4 py-2.5" /></label>
                        <label><span class="sr-only">状态</span><select v-model="query.status" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5"><option value="">全部状态</option><option value="pending">待审核</option><option value="published">已发布</option><option value="unpublished">未发布</option></select></label>
                        <label><span class="sr-only">评分</span><select v-model="query.rating" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5"><option value="">全部评分</option><option v-for="rating in 5" :key="rating" :value="String(rating)">{{ rating }} 星</option></select></label>
                        <div class="flex gap-2"><select v-model="query.sort" aria-label="排序" class="min-w-0 flex-1 rounded-xl border border-slate-200 bg-white px-3 py-2.5"><option value="newest">最新</option><option value="oldest">最早</option><option value="rating_desc">评分高</option><option value="rating_asc">评分低</option></select><button class="rounded-xl bg-slate-950 px-4 font-semibold text-white">筛选</button></div>
                    </form>
                </section>

                <section v-if="selected.length" class="flex flex-wrap items-center gap-3 rounded-xl border border-violet-200 bg-violet-50 p-4">
                    <strong>已选 {{ selected.length }} 条</strong><select v-model="bulkForm.status" :disabled="!canManage || bulkForm.processing" class="rounded-lg border border-violet-200 bg-white px-3 py-2"><option value="published">发布</option><option value="pending">移至待审核</option><option value="unpublished">取消发布</option></select><input v-if="bulkForm.status === 'unpublished'" v-model="bulkForm.reason" required maxlength="1000" placeholder="取消发布原因（必填）" class="min-w-64 flex-1 rounded-lg border border-violet-200 px-3 py-2" /><button :disabled="!canManage || bulkForm.processing || (bulkForm.status === 'unpublished' && !bulkForm.reason.trim())" class="rounded-lg bg-violet-700 px-4 py-2 font-semibold text-white disabled:opacity-50" @click="submitBulk">{{ bulkForm.processing ? '处理中…' : '应用' }}</button><p v-for="(error, field) in bulkForm.errors" :key="field" role="alert" class="basis-full text-red-700">{{ error }}</p>
                </section>

                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4"><label class="flex items-center gap-2 font-semibold"><input type="checkbox" :checked="reviews.data.length > 0 && selected.length === reviews.data.length" @change="toggleAll(($event.target as HTMLInputElement).checked)" />选择本页</label><a :href="exportUrl" class="font-semibold text-violet-700">导出当前筛选</a></div>
                    <div v-if="!reviews.data.length" class="px-6 py-16 text-center"><p class="text-lg font-semibold text-slate-900">暂无符合条件的评价</p><p class="mt-2 text-slate-500">调整筛选条件，或手动添加一条评价。</p></div>
                    <article v-for="review in reviews.data" :key="review.uuid" class="border-b border-slate-100 p-5 last:border-0">
                        <div class="flex items-start gap-4">
                            <input v-model="selected" :value="review.uuid" type="checkbox" :aria-label="`选择 ${review.author_name} 的评价`" class="mt-1" />
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2"><span class="tracking-wider text-amber-500" :aria-label="`${review.rating} 星`">{{ stars(review.rating) }}</span><span class="rounded-full px-2.5 py-1 text-[12px] font-semibold" :class="statusClass(review.status)">{{ statusLabel(review.status) }}</span><span v-if="review.featured" class="rounded-full bg-violet-50 px-2.5 py-1 text-[12px] font-semibold text-violet-700">精选</span><span v-if="review.verified_source !== 'none'" class="rounded-full bg-blue-50 px-2.5 py-1 text-[12px] font-semibold text-blue-700">已验证来源</span><span v-if="review.incentivized" class="rounded-full bg-orange-50 px-2.5 py-1 text-[12px] font-semibold text-orange-700">激励评价</span></div>
                                <h3 v-if="review.title" class="mt-3 font-semibold text-slate-950">{{ review.title }}</h3><p class="mt-2 whitespace-pre-wrap leading-6 text-slate-700">{{ review.body }}</p>
                                <dl v-if="review.form_answers?.length" class="mt-4 grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 md:grid-cols-2">
                                    <div v-for="answer in review.form_answers" :key="answer.question_id" class="min-w-0"><dt class="flex flex-wrap items-center gap-2 font-semibold text-slate-700">{{ answer.label }}<span v-if="!answer.public" class="rounded-full bg-slate-200 px-2 py-0.5 text-[12px] font-semibold text-slate-600">仅内部可见</span></dt><dd class="mt-1 break-words text-slate-600">{{ Array.isArray(answer.value) ? answer.value.join('、') : answer.value }}</dd></div>
                                </dl>
                                <div v-if="review.media?.length" class="mt-4 flex flex-wrap gap-2"><button v-for="(media, index) in review.media" :key="media.uuid" type="button" class="size-20 overflow-hidden rounded-xl border border-slate-200 bg-slate-100" :aria-label="`查看媒体 ${index + 1}`" @click="showMedia(review, index)"><img v-if="media.type.startsWith('image')" :src="media.url" alt="" class="h-full w-full object-cover" /><span v-else class="flex h-full items-center justify-center text-[12px] font-semibold">查看视频</span></button></div>
                                <div v-if="review.reply" class="mt-4 rounded-xl bg-slate-50 p-4"><p class="font-semibold text-slate-900">商家回复</p><p class="mt-1 whitespace-pre-wrap text-slate-600">{{ review.reply }}</p></div>
                                <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-slate-500"><span>{{ review.author_name }}</span><span>{{ review.kind === 'product' ? (review.product_title || '商品评价') : '店铺评价' }}</span><span>{{ formatDate(review.created_at) }}</span><button v-if="canManage" type="button" class="font-semibold text-violet-700" @click="openEditor(review)">审核与回复</button></div>
                            </div>
                        </div>
                    </article>
                    <div v-if="reviews.last_page > 1" class="flex items-center justify-between border-t border-slate-200 px-5 py-4"><button :disabled="reviews.current_page <= 1" class="rounded-lg border border-slate-200 px-4 py-2 font-semibold disabled:opacity-40" @click="goPage(reviews.current_page - 1, 'reviews')">上一页</button><span>第 {{ reviews.current_page }} / {{ reviews.last_page }} 页 · 共 {{ reviews.total }} 条</span><button :disabled="reviews.current_page >= reviews.last_page" class="rounded-lg border border-slate-200 px-4 py-2 font-semibold disabled:opacity-40" @click="goPage(reviews.current_page + 1, 'reviews')">下一页</button></div>
                </section>
            </template>

            <template v-else-if="tab === 'invitations'">
                <section class="grid gap-5 lg:grid-cols-3">
                    <form class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" @submit.prevent="scheduleInvitation"><h2 class="text-lg font-semibold text-slate-950">创建评价邀请</h2><p class="mt-2 text-slate-500">输入本店订单数字 ID。系统会幂等建立邀评记录；履约状态与发送条件验证通过后才能排期发送。</p><label class="mt-5 block font-medium">订单 ID<input v-model.number="inviteForm.order_id" :disabled="!canManage" type="number" min="1" required inputmode="numeric" placeholder="例如 1024" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3" /></label><p v-if="inviteForm.errors.order_id" class="mt-2 text-red-700">{{ inviteForm.errors.order_id }}</p><button :disabled="!canManage || inviteForm.processing" class="mt-5 w-full rounded-xl bg-violet-700 px-5 py-3 font-semibold text-white disabled:opacity-50">{{ inviteForm.processing ? '提交中…' : '建立邀评记录' }}</button></form>
                    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm lg:col-span-2">
                        <div class="border-b border-slate-200 px-5 py-4"><h2 class="text-lg font-semibold text-slate-950">邀请记录</h2></div>
                        <div v-if="!invitations.data.length" class="px-6 py-16 text-center text-slate-500">还没有邀请记录。</div>
                        <div v-for="invitation in invitations.data" :key="invitation.uuid" class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4 last:border-0">
                            <div><p class="font-semibold text-slate-950">订单 {{ invitation.order_number || `#${invitation.order_id}` }}</p><p v-if="invitation.product_title" class="mt-1 text-slate-600">{{ invitation.product_title }}</p><p class="mt-1 text-slate-500">{{ invitation.sent_at ? `初次邮件发送于 ${formatDate(invitation.sent_at)}` : invitation.due_at ? `计划于 ${formatDate(invitation.due_at)}` : `创建于 ${formatDate(invitation.created_at)}` }}</p><p v-if="invitation.reminder_sent_at" class="mt-1 text-slate-500">提醒发送于 {{ formatDate(invitation.reminder_sent_at) }}</p><p v-if="invitation.completed_at" class="mt-1 text-slate-500">评价提交于 {{ formatDate(invitation.completed_at) }}</p><p v-if="invitation.error_code" class="mt-1 text-[12px] text-slate-500">条件代码：{{ invitation.error_code }}</p></div>
                            <div class="flex items-center gap-3"><span class="rounded-full px-2.5 py-1 text-[12px] font-semibold" :class="statusClass(invitation.status || 'verification_required')">{{ statusLabel(invitation.status || 'verification_required') }}</span><button v-if="canManage && !['cancelled', 'completed', 'unsubscribed'].includes(invitation.status || '')" type="button" class="font-semibold text-red-700" @click="cancelInvitation(invitation)">取消</button></div>
                        </div>
                        <div v-if="invitations.last_page > 1" class="flex items-center justify-between border-t border-slate-200 px-5 py-4"><button :disabled="invitations.current_page <= 1" class="rounded-lg border px-4 py-2 disabled:opacity-40" @click="goPage(invitations.current_page - 1, 'invitations')">上一页</button><span>{{ invitations.current_page }} / {{ invitations.last_page }}</span><button :disabled="invitations.current_page >= invitations.last_page" class="rounded-lg border px-4 py-2 disabled:opacity-40" @click="goPage(invitations.current_page + 1, 'invitations')">下一页</button></div>
                    </section>
                </section>
            </template>

            <ReviewFormEditor v-else-if="tab === 'form'" :base-url="baseUrl" :can-manage="canManage" :products="products" :form-config="formConfig" @dirty="formEditorDirty = $event" @saved="notice = '评价表单已保存。'" />

            <form v-else-if="tab === 'settings'" class="space-y-5" @submit.prevent="saveSettings">
                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-4"><div><h2 class="text-lg font-semibold text-slate-950">收集与发布</h2><p class="mt-2 text-slate-500">控制评价展示和购买后邀请节奏。</p></div><label class="flex items-center gap-2 font-semibold"><input v-model="settingsForm.enabled" :disabled="!canManage" type="checkbox" />启用公开评价展示</label></div>
                    <div class="mt-6 grid gap-5 md:grid-cols-2 xl:grid-cols-4"><label class="font-medium">自动发布等待天数<input v-model.number="settingsForm.auto_publish_days" :disabled="!canManage" type="number" min="0" placeholder="留空为手动审核" class="mt-2 w-full rounded-xl border px-3 py-2.5" /></label><label class="font-medium">国内订单延迟天数<input v-model.number="settingsForm.domestic_delay_days" :disabled="!canManage" type="number" min="0" required class="mt-2 w-full rounded-xl border px-3 py-2.5" /></label><label class="font-medium">国际订单延迟天数<input v-model.number="settingsForm.international_delay_days" :disabled="!canManage" type="number" min="0" required class="mt-2 w-full rounded-xl border px-3 py-2.5" /></label><label class="font-medium">提醒间隔天数<input v-model.number="settingsForm.reminder_days" :disabled="!canManage" type="number" min="1" required class="mt-2 w-full rounded-xl border px-3 py-2.5" /></label></div>
                    <div class="mt-5 flex flex-wrap gap-5"><label class="flex items-center gap-2"><input v-model="settingsForm.invites_enabled" :disabled="!canManage" type="checkbox" />启用邀请排期</label><label class="flex items-center gap-2"><input v-model="settingsForm.auto_invites_enabled" :disabled="!canManage" type="checkbox" />自动识别新订单并建立邀评</label><label class="flex items-center gap-2"><input v-model="settingsForm.reminders_enabled" :disabled="!canManage" type="checkbox" />启用一次提醒</label><label class="flex items-center gap-2"><input v-model="settingsForm.marketing_only" :disabled="!canManage" type="checkbox" />仅邀请允许接收营销邮件的客户</label></div>
                    <div class="mt-5 rounded-xl border border-blue-100 bg-blue-50 p-4 text-blue-900"><p>自动识别只处理开启后进入系统的新订单，不回溯旧订单；每封初次邀评邮件最多发送一次提醒。</p><p v-if="settingsForm.auto_invites_since" class="mt-2 text-[12px] font-semibold">自动识别激活起点：{{ formatDate(settingsForm.auto_invites_since) }}（只读）</p><p v-else class="mt-2 text-[12px]">保存并启用自动识别后，系统会记录不可编辑的激活起点。</p></div>
                </section>
                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-semibold text-slate-950">展示样式</h2><div class="mt-5 grid gap-5 md:grid-cols-2 xl:grid-cols-4"><label class="font-medium">标题<input v-model="settingsForm.heading" :disabled="!canManage" maxlength="120" class="mt-2 w-full rounded-xl border px-3 py-2.5" /></label><label class="font-medium">星级颜色<input v-model="settingsForm.star_color" :disabled="!canManage" type="color" class="mt-2 h-11 w-full rounded-xl border p-1" /></label><label class="font-medium">卡片边角<select v-model="settingsForm.corner_style" :disabled="!canManage" class="mt-2 w-full rounded-xl border bg-white px-3 py-2.5"><option value="rounded">圆角</option><option value="square">直角</option></select></label><label class="font-medium">作者姓名<select v-model="settingsForm.display_name" :disabled="!canManage" class="mt-2 w-full rounded-xl border bg-white px-3 py-2.5"><option value="full">完整姓名</option><option value="initials">首字母缩写</option></select></label><label class="font-medium">布局<select v-model="settingsForm.layout" :disabled="!canManage" class="mt-2 w-full rounded-xl border bg-white px-3 py-2.5"><option value="grid">网格</option><option value="list">列表</option><option value="mosaic">拼贴</option></select></label><label class="font-medium">每页数量<select v-model.number="settingsForm.page_size" :disabled="!canManage" class="mt-2 w-full rounded-xl border bg-white px-3 py-2.5"><option :value="6">6</option><option :value="12">12</option><option :value="24">24</option></select></label></div><div class="mt-5 flex flex-wrap gap-5"><label class="flex items-center gap-2"><input v-model="settingsForm.show_verified" :disabled="!canManage" type="checkbox" />显示已验证标识</label><label class="flex items-center gap-2"><input v-model="settingsForm.show_incentive" :disabled="!canManage" type="checkbox" />显示激励评价标识</label></div></section>
                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-lg font-semibold text-slate-950">初次邀评邮件</h2><p class="mt-2 text-slate-500">使用已保存配置生成预览，预览不会发送邮件。</p></div><a :href="`${baseUrl}/email-preview?kind=initial`" target="_blank" rel="noopener" class="rounded-xl border border-slate-200 px-4 py-2.5 font-semibold text-violet-700">预览已保存的初次邮件</a></div>
                    <div class="mt-5 grid gap-5 md:grid-cols-2"><label class="font-medium">回复邮箱<input v-model="settingsForm.reply_to" :disabled="!canManage" type="email" class="mt-2 w-full rounded-xl border px-3 py-2.5" /></label><label class="font-medium">主题<input v-model="settingsForm.subject" :disabled="!canManage" maxlength="160" class="mt-2 w-full rounded-xl border px-3 py-2.5" /></label><label class="font-medium md:col-span-2">正文<textarea v-model="settingsForm.email_body" :disabled="!canManage" rows="7" class="mt-2 w-full rounded-xl border px-3 py-2.5"></textarea></label></div>
                </section>
                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-lg font-semibold text-slate-950">提醒邮件</h2><p class="mt-2 text-slate-500">只有尚未提交评价且符合条件的初次邀评，才可能收到一次提醒。</p></div><a :href="`${baseUrl}/email-preview?kind=reminder`" target="_blank" rel="noopener" class="rounded-xl border border-slate-200 px-4 py-2.5 font-semibold text-violet-700">预览已保存的提醒邮件</a></div>
                    <div class="mt-5 grid gap-5 md:grid-cols-2"><label class="font-medium">提醒主题<input v-model="settingsForm.reminder_subject" :disabled="!canManage" maxlength="160" class="mt-2 w-full rounded-xl border px-3 py-2.5" /></label><label class="font-medium">邮件按钮文字<input v-model="settingsForm.email_button_label" :disabled="!canManage" maxlength="80" class="mt-2 w-full rounded-xl border px-3 py-2.5" /></label><label class="font-medium">邮件强调色<input v-model="settingsForm.email_accent" :disabled="!canManage" type="color" class="mt-2 h-11 w-full rounded-xl border p-1" /></label><label class="font-medium md:col-span-2">提醒正文<textarea v-model="settingsForm.reminder_body" :disabled="!canManage" rows="7" class="mt-2 w-full rounded-xl border px-3 py-2.5"></textarea></label></div>
                </section>
                <div v-if="Object.keys(settingsForm.errors).length" role="alert" class="rounded-xl bg-red-50 p-4 text-red-700"><p v-for="(error, field) in settingsForm.errors" :key="field">{{ error }}</p></div><button :disabled="!canManage || settingsForm.processing" class="rounded-xl bg-violet-700 px-6 py-3 font-semibold text-white disabled:opacity-50">{{ settingsForm.processing ? '保存中…' : '保存设置' }}</button>
            </form>

            <template v-else-if="tab === 'imports'">
                <section class="grid gap-5 lg:grid-cols-3"><form class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" @submit.prevent="submitImport"><h2 class="text-lg font-semibold text-slate-950">导入 CSV</h2><p class="mt-2 text-slate-500">导入时会检查重复记录并安全跳过，不会覆盖已有评价。</p><label class="mt-5 block font-medium">CSV 文件<input :disabled="!canManage" required accept=".csv,text/csv" type="file" class="mt-2 block w-full rounded-xl border border-slate-200 p-3" @change="importForm.file = ($event.target as HTMLInputElement).files?.[0] || null" /></label><p v-if="importForm.errors.file" class="mt-2 text-red-700">{{ importForm.errors.file }}</p><button :disabled="!canManage || importForm.processing" class="mt-5 w-full rounded-xl bg-violet-700 px-5 py-3 font-semibold text-white disabled:opacity-50">{{ importForm.processing ? '上传中…' : '上传并导入' }}</button></form><section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm lg:col-span-2"><div class="border-b px-5 py-4"><h2 class="text-lg font-semibold text-slate-950">导入历史</h2></div><div v-if="!imports.length" class="px-6 py-16 text-center text-slate-500">还没有导入记录。</div><div v-for="row in imports" :key="row.uuid" class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-5 py-4 last:border-0"><div><p class="font-semibold text-slate-950">CSV 导入</p><p class="mt-1 text-slate-500">{{ formatDate(row.created_at) }} · 导入 {{ row.imported || 0 }} · 跳过 {{ row.skipped || 0 }}</p><ul v-if="row.errors?.length" class="mt-2 space-y-1 text-[12px] text-amber-800"><li v-for="error in row.errors.slice(0, 5)" :key="`${error.line}-${error.code}`">第 {{ error.line }} 行：{{ error.code }}</li><li v-if="row.errors.length > 5">另有 {{ row.errors.length - 5 }} 条错误（仅显示前 5 条）</li></ul></div><div class="flex items-center gap-3"><span class="rounded-full px-2.5 py-1 text-[12px] font-semibold" :class="statusClass(row.status || 'processing')">{{ statusLabel(row.status || 'processing') }}</span><button v-if="canManage && row.can_undo" type="button" class="font-semibold text-red-700" @click="undoImport(row)">撤销本次导入</button></div></div></section></section>
            </template>

            <template v-else-if="tab === 'widgets'">
                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-lg font-semibold text-slate-950">评价展示组件</h2><p class="mt-2 max-w-3xl text-slate-500">预览九种当前支持的展示模式。预览数据经过公开脱敏，不包含评价者邮箱或管理字段。</p><div class="mt-5"><a :href="`${baseUrl}/preview`" target="_blank" rel="noopener" class="rounded-xl border border-slate-200 px-5 py-3 font-semibold">查看公开预览数据</a></div></section>
                <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"><article v-for="mode in widgetModes" :key="mode.key" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="h-32 rounded-xl bg-slate-50 p-4" :class="mode.visual" aria-hidden="true"><template v-if="mode.key === 'stars' || mode.key === 'trust'"><span class="text-xl tracking-wider text-amber-500">★★★★★</span></template><template v-else-if="mode.key === 'video'"><span class="flex size-12 items-center justify-center rounded-full bg-violet-700 text-xl text-white">▶</span></template><template v-else-if="mode.key === 'sidebar' || mode.key === 'floating'"><span class="w-20 rounded-lg bg-violet-700 p-3 text-center font-semibold text-white">4.9 ★</span></template><template v-else><span v-for="n in mode.key === 'gallery' ? 6 : 3" :key="n" class="block min-h-5 flex-1 rounded-lg border border-slate-200 bg-white p-2"><span class="block h-2 w-2/3 rounded bg-slate-200"></span><span class="mt-2 block h-2 w-full rounded bg-slate-100"></span></span></template></div><h3 class="mt-4 font-semibold text-slate-950">{{ mode.name }}</h3><p class="mt-2 text-slate-500">{{ mode.description }}</p><a :href="`${baseUrl}/widget-preview?mode=${mode.key}`" target="_blank" rel="noopener" class="mt-3 inline-block font-semibold text-violet-700">打开预览</a></article></section>
                <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5"><h2 class="font-semibold text-amber-950">集成状态</h2><p class="mt-2 text-amber-900">提供站内组件预览与 Shopify 主题应用块；店铺安装本应用后，可在主题编辑器中添加 Deco Reviews。外部邮件平台和第三方评价平台连接仍未实现。</p></section>
            </template>
        </div>

        <div v-if="createOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4" role="presentation" @click.self="createOpen = false"><form role="dialog" aria-modal="true" aria-labelledby="create-review-title" class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl" @submit.prevent="submitReview"><div class="flex items-center justify-between"><h2 id="create-review-title" class="text-xl font-semibold">添加评价</h2><button type="button" aria-label="关闭" class="rounded-lg p-2 text-xl" @click="createOpen = false">×</button></div><div class="mt-5 grid gap-4 md:grid-cols-2"><label class="font-medium">评价类型<select v-model="createForm.kind" class="mt-2 w-full rounded-xl border px-3 py-2.5"><option value="product">商品评价</option><option value="store">店铺评价</option></select></label><label v-if="createForm.kind === 'product'" class="font-medium">商品<select v-model="createForm.product_id" required class="mt-2 w-full rounded-xl border bg-white px-3 py-2.5"><option :value="null" disabled>选择商品</option><option v-for="product in products" :key="product.id" :value="product.id">{{ product.title }}</option></select></label><label class="font-medium">作者姓名<input v-model="createForm.author_name" required maxlength="120" class="mt-2 w-full rounded-xl border px-3 py-2.5" /></label><label class="font-medium">作者邮箱<input v-model="createForm.author_email" type="email" maxlength="254" class="mt-2 w-full rounded-xl border px-3 py-2.5" /></label><label class="font-medium">评分<select v-model.number="createForm.rating" class="mt-2 w-full rounded-xl border bg-white px-3 py-2.5"><option v-for="rating in 5" :key="rating" :value="rating">{{ rating }} 星</option></select></label><label class="font-medium">标题<input v-model="createForm.title" maxlength="200" class="mt-2 w-full rounded-xl border px-3 py-2.5" /></label><label class="font-medium md:col-span-2">评价内容<textarea v-model="createForm.body" required rows="5" class="mt-2 w-full rounded-xl border px-3 py-2.5"></textarea></label><label class="font-medium md:col-span-2">图片或视频（可选）<input multiple accept="image/*,video/*" type="file" class="mt-2 block w-full rounded-xl border p-3" @change="createForm.media = Array.from(($event.target as HTMLInputElement).files || [])" /></label></div><div v-if="Object.keys(createForm.errors).length" role="alert" class="mt-4 rounded-xl bg-red-50 p-4 text-red-700"><p v-for="(error, field) in createForm.errors" :key="field">{{ error }}</p></div><div class="mt-6 flex justify-end gap-3"><button type="button" class="rounded-xl border px-5 py-2.5 font-semibold" @click="createOpen = false">取消</button><button :disabled="createForm.processing" class="rounded-xl bg-violet-700 px-5 py-2.5 font-semibold text-white disabled:opacity-50">{{ createForm.processing ? '提交中…' : '创建评价' }}</button></div></form></div>

        <div v-if="editingReview" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4" role="presentation" @click.self="editingReview = null"><form role="dialog" aria-modal="true" aria-labelledby="edit-review-title" class="w-full max-w-xl rounded-2xl bg-white p-6 shadow-2xl" @submit.prevent="saveReview"><div class="flex items-center justify-between"><h2 id="edit-review-title" class="text-xl font-semibold">审核与回复</h2><button type="button" aria-label="关闭" class="rounded-lg p-2 text-xl" @click="editingReview = null">×</button></div><label class="mt-5 block font-medium">状态<select v-model="editForm.status" class="mt-2 w-full rounded-xl border bg-white px-3 py-2.5"><option value="pending">待审核</option><option value="published">已发布</option><option value="unpublished">未发布</option></select></label><label v-if="editForm.status === 'unpublished'" class="mt-4 block font-medium">取消发布原因<input v-model="editForm.reason" required class="mt-2 w-full rounded-xl border px-3 py-2.5" /></label><label class="mt-4 flex items-center gap-2 font-medium"><input v-model="editForm.featured" type="checkbox" />设为精选评价</label><label class="mt-4 block font-medium">商家回复<textarea v-model="editForm.reply" rows="5" class="mt-2 w-full rounded-xl border px-3 py-2.5"></textarea></label><div v-if="Object.keys(editForm.errors).length" role="alert" class="mt-4 rounded-xl bg-red-50 p-4 text-red-700"><p v-for="(error, field) in editForm.errors" :key="field">{{ error }}</p></div><div class="mt-6 flex justify-end gap-3"><button type="button" class="rounded-xl border px-5 py-2.5 font-semibold" @click="editingReview = null">取消</button><button :disabled="editForm.processing" class="rounded-xl bg-violet-700 px-5 py-2.5 font-semibold text-white disabled:opacity-50">保存</button></div></form></div>

        <div v-if="activeMedia" id="review-media-dialog" role="dialog" aria-modal="true" aria-label="评价媒体查看器" tabindex="-1" class="fixed inset-0 z-[60] flex items-center justify-center bg-slate-950/90 p-4 outline-none" @click.self="activeMedia = null" @keydown="onDialogKey"><button type="button" aria-label="关闭媒体查看器" class="absolute right-5 top-5 rounded-full bg-white/10 px-4 py-2 text-xl text-white" @click="activeMedia = null">×</button><button v-if="activeMedia.review.media.length > 1" type="button" aria-label="上一项" class="absolute left-4 rounded-full bg-white/10 px-4 py-3 text-2xl text-white" @click="moveMedia(-1)">‹</button><img v-if="activeMedia.review.media[activeMedia.index].type.startsWith('image')" :src="activeMedia.review.media[activeMedia.index].url" alt="评价媒体大图" class="max-h-[85vh] max-w-[85vw] object-contain" /><video v-else :src="activeMedia.review.media[activeMedia.index].url" controls class="max-h-[85vh] max-w-[85vw]" /><button v-if="activeMedia.review.media.length > 1" type="button" aria-label="下一项" class="absolute right-4 rounded-full bg-white/10 px-4 py-3 text-2xl text-white" @click="moveMedia(1)">›</button><p class="absolute bottom-5 text-white">{{ activeMedia.index + 1 }} / {{ activeMedia.review.media.length }}</p></div>
    </AppLayout>
</template>
