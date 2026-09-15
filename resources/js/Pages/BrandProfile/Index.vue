<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import EmptyState from '../../Components/Feedback/EmptyState.vue';
import AppIcon from '../../Components/Layout/AppIcon.vue';
import { brandProfileSections, type BrandProfileSection } from '../../config/brandProfile';
import AppLayout from '../../Layouts/AppLayout.vue';

type Cell = { text?: string; links?: { text: string; url: string }[]; secret?: boolean; configured?: boolean };
type Row = { id: string; cells: Record<string, Cell> };
type Asset = { id: string; name: string; kind: 'image' | 'file'; url: string };
const props = defineProps<{
    section: BrandProfileSection;
    store: { id: number; name: string; shopify_domain: string; currency: string | null; timezone: string | null } | null;
    profile: { configured: boolean; inherited: boolean; source_url: string | null; synced_at: string | null; columns: string[]; rows: Row[]; assets: Asset[] } | null;
    canReveal: boolean;
}>();
const page = usePage();
const currentSection = computed(() => brandProfileSections.find((item) => item.key === props.section) ?? brandProfileSections[0]);
const search = ref('');
const syncing = ref(false);
const revealed = ref<Record<string, string>>({});
const loadingRows = ref<Record<string, boolean>>({});
const error = ref('');
const preview = ref<Asset | null>(null);
const previewDialog = ref<HTMLDialogElement | null>(null);
const timers = new Map<string, ReturnType<typeof setTimeout>>();
const requests = new Map<string, AbortController>();
const rows = computed(() => (props.profile?.rows ?? []).filter((row) => Object.values(row.cells)
    .some((cell) => !cell.secret && (cell.text ?? '').toLocaleLowerCase().includes(search.value.trim().toLocaleLowerCase()))));
const images = computed(() => props.profile?.assets.filter((asset) => asset.kind === 'image') ?? []);
const files = computed(() => props.profile?.assets.filter((asset) => asset.kind === 'file') ?? []);
const syncedAt = computed(() => props.profile?.synced_at ? new Intl.DateTimeFormat('zh-CN', { dateStyle: 'short', timeStyle: 'short', timeZone: 'Asia/Shanghai' }).format(new Date(props.profile.synced_at)) : '尚未同步');
const clearSecrets = () => {
    requests.forEach((request) => request.abort());
    requests.clear();
    timers.forEach(clearTimeout);
    timers.clear();
    revealed.value = {};
    loadingRows.value = {};
};
const onVisibilityChange = () => { if (document.hidden) clearSecrets(); };
watch(() => [props.store?.id, props.section, props.profile?.synced_at], () => {
    clearSecrets();
    search.value = '';
    error.value = '';
    previewDialog.value?.close();
    preview.value = null;
});
onMounted(() => document.addEventListener('visibilitychange', onVisibilityChange));
onBeforeUnmount(() => { clearSecrets(); document.removeEventListener('visibilitychange', onVisibilityChange); });

const togglePassword = async (row: Row) => {
    if (row.id in revealed.value) {
        delete revealed.value[row.id];
        clearTimeout(timers.get(row.id));
        timers.delete(row.id);
        return;
    }
    if (!props.store || !props.canReveal || loadingRows.value[row.id]) return;
    const controller = new AbortController();
    requests.set(row.id, controller);
    loadingRows.value[row.id] = true;
    error.value = '';
    try {
        const response = await fetch(`/brand-profile/${props.store.id}/${props.section}/rows/${row.id}/password`, {
            credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Store-ID': String(props.store.id) }, signal: controller.signal,
        });
        if (!response.ok) throw new Error(response.status === 403 ? '当前账号没有查看密码的权限。' : '密码读取失败，资料可能已更新，请刷新页面重试。');
        const payload = await response.json();
        if (typeof payload.data?.value !== 'string') throw new Error('密码读取失败，请稍后重试。');
        if (controller.signal.aborted || document.hidden) return;
        revealed.value[row.id] = payload.data.value;
        timers.set(row.id, setTimeout(() => { delete revealed.value[row.id]; timers.delete(row.id); }, 30000));
    } catch (reason) {
        if (!controller.signal.aborted) error.value = reason instanceof Error ? reason.message : '密码读取失败，请稍后重试。';
    } finally {
        requests.delete(row.id);
        delete loadingRows.value[row.id];
    }
};
const refresh = () => {
    if (!props.store || syncing.value) return;
    clearSecrets();
    router.post(`/brand-profile/${props.store.id}/refresh`, {}, { preserveScroll: true, onStart: () => { syncing.value = true; }, onFinish: () => { syncing.value = false; } });
};
const showImage = (asset: Asset) => { preview.value = asset; previewDialog.value?.showModal(); };
const badgeClass = (text?: string) => text?.includes('敏感') ? 'bg-rose-50 text-rose-700 ring-rose-100' : 'bg-blue-50 text-blue-700 ring-blue-100';
const remainingText = (cell: Cell) => (cell.links ?? []).reduce((text, link) => text.replace(link.text, ''), cell.text ?? '').trim();
</script>

<template>
    <Head :title="`品牌资料 · ${currentSection.name}`" />
    <AppLayout :breadcrumbs="[{ label: '品牌资料', href: '/brand-profile/overview' }, { label: currentSection.name }]">
        <div class="brand-profile mx-auto max-w-[1600px] space-y-5">
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <header class="flex flex-wrap items-center justify-between gap-4 px-6 py-5">
                    <div class="flex min-w-0 items-center gap-4">
                        <span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-emerald-50 text-emerald-700"><AppIcon name="brand-profile" :size="25" /></span>
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h1 class="text-2xl font-semibold text-slate-950">品牌资料</h1>
                                <span v-if="profile?.inherited" class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700">公司共享</span>
                            </div>
                            <p class="mt-1 text-sm text-slate-500">{{ store?.name ?? '请选择店铺' }} · 账号、密码与执照资料</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <a v-if="profile?.source_url" :href="profile.source_url" target="_blank" rel="noopener noreferrer" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-600 hover:border-blue-300 hover:text-blue-600">在飞书打开 ↗</a>
                        <button v-if="canReveal && profile?.configured" type="button" :disabled="syncing" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50" @click="refresh">{{ syncing ? '正在同步…' : '刷新资料' }}</button>
                    </div>
                </header>
                <nav class="flex gap-6 overflow-x-auto border-t border-slate-100 px-6 sm:gap-8" aria-label="品牌资料分类">
                    <Link v-for="item in brandProfileSections" :key="item.key" :href="item.route" :aria-current="section === item.key ? 'page' : undefined" class="shrink-0 border-b-2 py-4 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-blue-600" :class="section === item.key ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-900'">{{ item.name }}</Link>
                </nav>
            </section>

            <div v-if="error || page.props.errors.brand_profile" role="alert" class="rounded-xl border border-rose-200 bg-rose-50 px-5 py-3 text-sm text-rose-700">{{ error || page.props.errors.brand_profile }}</div>
            <EmptyState v-if="!store" title="尚未选择店铺" description="请选择有权访问的店铺，再查看对应的品牌资料。" icon="stores" />
            <EmptyState v-else-if="!profile?.configured" title="尚未配置品牌资料来源" description="请在同一公司任一店铺的飞书设置中填写品牌资料原表链接。" icon="brand-profile" action-label="打开飞书设置" action-href="/store-settings/feishu" />
            <template v-else>
                <div class="flex flex-wrap items-center justify-between gap-3 px-1 text-sm text-slate-500">
                    <p>密码默认隐藏，点击眼睛图标查看，30 秒后自动隐藏。敏感资料请勿外传。</p>
                    <p class="shrink-0 text-xs">最近同步：{{ syncedAt }}（北京时间）</p>
                </div>
                <section v-if="section !== 'business-licenses' && profile.rows.length" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                        <h2 class="font-semibold text-slate-900">{{ currentSection.name }} <span class="ml-2 text-sm font-normal text-slate-400">{{ rows.length }} 条</span></h2>
                        <input v-model="search" type="search" aria-label="搜索品牌资料" placeholder="搜索资料…" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-blue-500 sm:w-64" />
                    </div>
                    <div class="max-h-[calc(100dvh-340px)] overflow-auto">
                        <table class="w-full min-w-[920px] table-fixed border-collapse text-left" :class="section === 'plugins' ? 'min-w-[1120px]' : ''">
                            <thead class="sticky top-0 z-10 bg-slate-50 text-slate-500"><tr><th v-for="column in profile.columns" :key="column" scope="col" class="border-r border-b border-slate-200 px-4 py-3 font-medium last:border-r-0">{{ column }}</th></tr></thead>
                            <tbody class="divide-y divide-slate-200">
                                <tr v-for="row in rows" :key="row.id" class="align-middle even:bg-slate-50/60 hover:bg-blue-50/40">
                                    <td v-for="column in profile.columns" :key="column" class="border-r border-slate-200 px-4 py-3 last:border-r-0">
                                        <template v-if="row.cells[column]?.secret">
                                            <div v-if="row.cells[column].configured" class="flex items-center gap-2">
                                                <span class="break-all font-mono" :class="row.id in revealed ? 'text-slate-900' : 'tracking-widest text-slate-500'">{{ revealed[row.id] ?? '••••••••' }}</span>
                                                <button v-if="canReveal" type="button" :aria-label="`${row.id in revealed ? '隐藏' : '查看'}第${profile.rows.indexOf(row) + 1}行密码`" :aria-pressed="row.id in revealed" :disabled="loadingRows[row.id]" class="shrink-0 rounded-md p-1.5 text-slate-400 hover:bg-blue-50 hover:text-blue-600 focus-visible:outline-2 focus-visible:outline-blue-600 disabled:opacity-40" @click="togglePassword(row)">
                                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/><path v-if="row.id in revealed" d="m3 3 18 18"/></svg>
                                                </button>
                                                <span v-else class="text-xs text-slate-400">受限</span>
                                            </div>
                                            <span v-else class="text-slate-400">未提供</span>
                                        </template>
                                        <span v-else-if="column === '类型' && row.cells[column]?.text" class="brand-type-badge inline-flex rounded px-2 py-1 font-medium ring-1 ring-inset" :class="badgeClass(row.cells[column].text)">{{ row.cells[column].text }}</span>
                                        <template v-else>
                                            <p v-if="row.cells[column]?.text && (!row.cells[column]?.links?.length || column === '负责人/对接人')" class="whitespace-pre-wrap break-words text-slate-700">{{ row.cells[column].text }}</p>
                                            <template v-else-if="row.cells[column]?.links?.length">
                                                <a v-for="(link, index) in row.cells[column].links" :key="index" :href="link.url" target="_blank" rel="noopener noreferrer" class="block break-words text-blue-600 hover:underline">{{ link.text }}</a>
                                                <p v-if="remainingText(row.cells[column])" class="mt-1 whitespace-pre-wrap break-words text-slate-600">{{ remainingText(row.cells[column]) }}</p>
                                            </template>
                                            <span v-else class="text-slate-400">—</span>
                                        </template>
                                    </td>
                                </tr>
                                <tr v-if="!rows.length"><td :colspan="profile.columns.length" class="px-6 py-12 text-center text-slate-500">没有匹配的资料，请尝试其他关键词。</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
                <section v-else-if="section === 'business-licenses' && profile.assets.length" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-slate-900">营业执照</h2>
                    <div v-if="images.length" class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        <button v-for="asset in images" :key="asset.id" type="button" class="group text-left" :aria-label="`放大查看${asset.name}`" @click="showImage(asset)">
                            <span class="flex h-72 items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-slate-50 p-3 transition group-hover:border-blue-400"><img :src="asset.url" :alt="asset.name" class="max-h-full max-w-full object-contain" /></span>
                            <span class="mt-3 block text-sm text-slate-500">{{ asset.name }} <span class="text-blue-600">点击放大</span></span>
                        </button>
                    </div>
                    <div v-if="files.length" class="max-w-xl space-y-3">
                        <a v-for="asset in files" :key="asset.id" :href="asset.url" target="_blank" rel="noopener noreferrer" class="flex items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 hover:border-blue-300"><AppIcon name="audit" :size="20" /><span class="min-w-0 flex-1 break-words">{{ asset.name }}</span><span class="text-sm text-blue-600">查看 ↗</span></a>
                    </div>
                    <a v-if="profile.source_url" :href="profile.source_url" target="_blank" rel="noopener noreferrer" class="inline-flex text-sm text-blue-600 hover:underline">在飞书查看原表 ↗</a>
                </section>
                <EmptyState v-else :title="`暂无${currentSection.name}资料`" description="点击刷新资料，从公司共享的飞书原表获取最新内容。" :icon="currentSection.icon" />
            </template>
        </div>
        <dialog ref="previewDialog" class="m-auto w-[min(1100px,94vw)] max-w-none rounded-2xl bg-white p-0 shadow-2xl backdrop:bg-slate-950/70" @click="event => { if (event.target === previewDialog) previewDialog?.close(); }">
            <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-4"><h2 class="font-semibold">{{ preview?.name }}</h2><button autofocus type="button" aria-label="关闭执照预览" class="rounded-lg px-3 py-1.5 text-slate-600 hover:bg-slate-100" @click="previewDialog?.close()">关闭 ✕</button></div>
            <div class="flex max-h-[80dvh] justify-center overflow-auto bg-slate-50 p-4"><img v-if="preview" :src="preview.url" :alt="preview.name" class="max-h-[75dvh] max-w-full object-contain" /></div>
        </dialog>
    </AppLayout>
</template>

<style scoped>
.brand-profile table { font-size: 14px; line-height: 1.65; }
.brand-type-badge { font-size: 14px; line-height: 20px; }
</style>
