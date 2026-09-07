<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import AppLayout from '../../../../../resources/js/Layouts/AppLayout.vue';
import '../../../extensions/community-reviews/assets/community-reviews.css';
import '../../carousel.js';

type Folder = { id: string; name: string; image_count: number; product_id: number | null; product_title: string | null; product_status: string | null; label: string; series: string; aliases: string[]; enabled: boolean };
type Product = { id: number; title: string; status: string };
const props = defineProps<{ organization: {id: number; name: string}; store: {id: number; name: string; shopify_domain: string}; settings: {enabled: boolean; heading: string; read_more_url: string | null; card_count: number}; folders: Folder[]; connected: boolean; canManage: boolean; reviewCount: number }>();
const base = `/organizations/${props.organization.id}/stores/${props.store.id}/community-reviews`;
const rows = ref(props.folders.map(folder => ({...folder, aliasText: folder.aliases.join(', ')})));
const form = useForm({...props.settings, read_more_url: props.settings.read_more_url || '', models: [] as unknown[]});
const products = ref<Product[]>([]);
const search = ref('');
const notice = ref('');
const loading = ref(false);
const previewHost = ref<HTMLElement | null>(null);
let previewInstance: {destroy: () => void} | undefined;
let productRequest: AbortController | undefined;
const selectedProducts = (row: typeof rows.value[number]) => {
    const options = [...products.value];
    if (row.product_id && !options.some(product => product.id === row.product_id)) options.unshift({id: row.product_id, title: row.product_title || row.label, status: row.product_status || 'active'});
    return options;
};
const loadProducts = async () => {
    productRequest?.abort(); productRequest = new AbortController();
    try {
        const response = await fetch(`${base}/products?q=${encodeURIComponent(search.value)}`, {headers: {Accept: 'application/json'}, signal: productRequest.signal});
        if (!response.ok) throw new Error('读取商品失败，请稍后重试。');
        products.value = (await response.json()).data;
    } catch (error) { if ((error as Error).name !== 'AbortError') notice.value = (error as Error).message; }
};
const save = () => {
    form.models = rows.value.filter(row => row.product_id).map(row => ({folder_id:row.id, product_id:row.product_id, label:row.label, series:row.series, aliases:row.aliasText.split(/[,，\n]/).map(value => value.trim()).filter(Boolean), enabled:row.enabled}));
    form.put(base, {preserveScroll: true, onSuccess: () => { notice.value = '设置已保存，店铺展示将使用新的配置。'; }});
};
const preview = async () => {
    loading.value = true; notice.value = '';
    try {
        const response = await fetch(`${base}/preview`, {headers:{Accept:'application/json'}});
        const result = await response.json();
        if (!response.ok) throw new Error(result.error?.message || '预览加载失败。');
        if (!result.data.cards.length) { notice.value = '暂无可展示的评论。请确认已保存车型关联、已上传素材，并有符合条件的评论。'; }
        await nextTick(); previewInstance?.destroy();
        const root = previewHost.value!; root.hidden = false;
        root.querySelector('[data-cr-heading]')!.textContent = result.data.heading;
        previewInstance = (window as any).CommunityReviews.mount(root, result.data);
    } catch (error) { notice.value = (error as Error).message; }
    finally { loading.value = false; }
};
onMounted(loadProducts);
onBeforeUnmount(() => { previewInstance?.destroy(); productRequest?.abort(); });
</script>

<template>
    <Head title="买家秀评价" />
    <AppLayout :breadcrumbs="[{label:'工作台',href:'/dashboard'},{label:'应用中心'},{label:'买家秀评价'}]">
        <div class="mx-auto max-w-7xl space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div><p class="text-sm font-semibold text-emerald-600">{{ store.name }}</p><h1 class="mt-1 text-3xl font-semibold text-slate-950">买家秀评价</h1><p class="mt-2 text-sm text-slate-500">让真实评价与在售车型一起展示。</p></div>
                <a href="/model-assets" class="rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold">管理车型素材</a>
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-2xl border border-slate-200 bg-white p-5"><p class="text-sm text-slate-500">可抽取的四星 / 五星评论</p><p class="mt-2 text-3xl font-semibold">{{ reviewCount }}</p></div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5"><p class="text-sm text-slate-500">车型素材文件夹</p><p class="mt-2 text-3xl font-semibold">{{ folders.length }}</p></div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5"><p class="text-sm text-slate-500">Shopify 应用连接</p><p class="mt-3 font-semibold" :class="connected ? 'text-emerald-600' : 'text-amber-600'">{{ connected ? '已连接' : '尚未连接' }}</p><p v-if="!connected" class="mt-2 text-sm text-slate-500">安装后，在 Shopify 后台打开一次 Community Reviews。</p></div>
            </div>
            <form class="space-y-6" @submit.prevent="save">
                <section class="rounded-2xl border border-slate-200 bg-white p-6">
                    <div class="flex items-center justify-between"><h2 class="text-lg font-semibold">店铺统一设置</h2><label class="flex items-center gap-2 text-sm"><input v-model="form.enabled" :disabled="!canManage" type="checkbox" class="size-4 accent-emerald-600" />启用店铺展示</label></div>
                    <div class="mt-5 grid gap-5 md:grid-cols-2">
                        <label class="text-sm font-medium">展示标题<input v-model="form.heading" :disabled="!canManage" required maxlength="200" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3" /></label>
                        <label class="text-sm font-medium">Read more 统一网址<input v-model="form.read_more_url" :disabled="!canManage" type="url" placeholder="https://…" maxlength="2048" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3" /><span class="mt-2 block text-xs text-slate-500">本店设置一次，所有评价共用。留空则隐藏 Read more。</span></label>
                        <label class="text-sm font-medium">每次抽取数量<input v-model.number="form.card_count" :disabled="!canManage" type="number" min="2" max="24" class="ml-4 w-24 rounded-xl border border-slate-200 px-3 py-2" /></label>
                    </div>
                </section>
                <section class="rounded-2xl border border-slate-200 bg-white p-6">
                    <h2 class="text-lg font-semibold">素材与车型关联</h2><p class="mt-2 text-sm text-slate-500">每个文件夹关联本店商品。只有在售且已发布到在线商店的车型会展示；下架或没有素材时自动跳过。</p>
                    <div class="mt-5 flex gap-3"><input v-model="search" type="search" placeholder="搜索店铺商品" class="w-full max-w-md rounded-xl border border-slate-200 px-4 py-3" @keydown.enter.prevent="loadProducts" /><button type="button" class="rounded-xl bg-slate-100 px-5 text-sm font-semibold" @click="loadProducts">搜索</button></div>
                    <p class="mt-2 text-xs text-slate-500">每次最多显示 30 个商品，可输入名称缩小范围。</p>
                    <p v-if="!rows.length" class="my-8 text-center text-slate-500">请先在「车型素材」中创建文件夹并上传图片。</p>
                    <div v-for="row in rows" :key="row.id" class="mt-5 rounded-xl border border-slate-200 p-5">
                        <div class="flex items-center justify-between"><div><strong>{{ row.name }}</strong><span class="ml-3 text-xs text-slate-500">{{ row.image_count }} 张素材</span></div><label class="flex items-center gap-2 text-sm"><input v-model="row.enabled" :disabled="!canManage" type="checkbox" />参与展示</label></div>
                        <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                            <label class="text-xs text-slate-600">对应商品<select v-model="row.product_id" :disabled="!canManage" class="mt-2 w-full rounded-lg border border-slate-200 bg-white p-3"><option :value="null">不关联</option><option v-for="product in selectedProducts(row)" :key="product.id" :value="product.id">{{ product.title }}{{ product.status !== 'active' ? '（未在售）' : '' }}</option></select></label>
                            <label class="text-xs text-slate-600">车型名称<input v-model="row.label" :disabled="!canManage" maxlength="120" class="mt-2 w-full rounded-lg border border-slate-200 p-3" /></label>
                            <label class="text-xs text-slate-600">系列说明<input v-model="row.series" :disabled="!canManage" maxlength="120" placeholder="例如 CLASSIC MODELS" class="mt-2 w-full rounded-lg border border-slate-200 p-3" /></label>
                            <label class="text-xs text-slate-600">评论中的其他叫法<input v-model="row.aliasText" :disabled="!canManage" placeholder="多个名称用逗号分隔" class="mt-2 w-full rounded-lg border border-slate-200 p-3" /></label>
                        </div>
                    </div>
                </section>
                <div v-if="Object.keys(form.errors).length" role="alert" class="rounded-xl bg-red-50 p-4 text-sm text-red-700"><p v-for="(error, field) in form.errors" :key="field">{{ error }}</p></div>
                <div class="flex flex-wrap items-center gap-3"><button :disabled="!canManage || form.processing" class="rounded-xl bg-slate-950 px-6 py-3 font-semibold text-white disabled:opacity-50">{{ form.processing ? '保存中…' : '保存设置' }}</button><button type="button" :disabled="loading || !connected" class="rounded-xl border border-slate-200 bg-white px-6 py-3 font-semibold disabled:opacity-50" @click="preview">{{ loading ? '加载中…' : '随机预览已保存的配置' }}</button></div>
            </form>
            <p v-if="notice" role="status" class="rounded-xl bg-slate-100 p-4 text-sm text-slate-700">{{ notice }}</p>
            <section ref="previewHost" class="cr-widget rounded-2xl bg-white" data-editor="true" data-autoplay="false" style="--cr-spacing:32px" hidden><h2 class="cr-heading" data-cr-heading></h2><div class="cr-viewport" data-cr-viewport tabindex="0" role="region" aria-label="买家秀预览，左右拖动或使用方向键浏览"><div class="cr-track" data-cr-track></div></div></section>
        </div>
    </AppLayout>
</template>
