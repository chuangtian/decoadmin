<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, defineComponent, h, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';

type Kind = 'product_amount' | 'order_amount' | 'bxgy' | 'free_shipping';
interface DiscountRow {
    id: string;
    kind: Kind | 'app';
    shopify_type: string;
    title: string;
    summary: string;
    status: string;
    codes: string[];
    starts_at: string | null;
    ends_at: string | null;
    usage_limit: number | null;
    usage_count: number;
    applies_once_per_customer: boolean;
    combines_with: { orderDiscounts?: boolean; productDiscounts?: boolean; shippingDiscounts?: boolean };
    value_type: 'percentage' | 'fixed_amount' | null;
    value: number | null;
    minimum_type: 'none' | 'subtotal' | 'quantity';
    minimum_subtotal: string | null;
    minimum_quantity: string | null;
    product_ids: string[];
    buys_product_ids: string[];
    gets_product_ids: string[];
    buys_quantity: string | null;
    gets_quantity: string | null;
    gets_percentage: number | null;
    uses_per_order_limit: number | null;
    editable: boolean;
}
interface ProductOption { id: string; title: string; image: string | null }

const ProductPickerTitle = defineComponent({
    props: { title: { type: String, required: true }, count: { type: Number, required: true } },
    setup(componentProps) {
        return () => h('div', { class: 'flex items-center justify-between gap-4' }, [
            h('h3', { class: 'font-semibold text-slate-950' }, componentProps.title),
            h('span', { class: 'rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600' }, `已选择 ${componentProps.count}`),
        ]);
    },
});
const ProductPicker = defineComponent({
    props: {
        products: { type: Array as () => ProductOption[], required: true },
        selected: { type: Array as () => string[], required: true },
        search: { type: String, required: true },
    },
    emits: ['toggle', 'update:search'],
    setup(componentProps, { emit }) {
        return () => h('div', { class: 'mt-4' }, [
            h('input', { value: componentProps.search, type: 'search', placeholder: '搜索当前店铺商品', class: 'h-10 w-full rounded-xl border-slate-300 text-sm', onInput: (event: Event) => emit('update:search', (event.target as HTMLInputElement).value) }),
            h('div', { class: 'mt-3 max-h-64 overflow-y-auto rounded-xl border border-slate-200' }, componentProps.products.length ? componentProps.products.map(product => h('label', { key: product.id, class: 'flex cursor-pointer items-center gap-3 border-b border-slate-100 p-3 last:border-0 hover:bg-slate-50' }, [
                h('input', { type: 'checkbox', checked: componentProps.selected.includes(product.id), class: 'rounded border-slate-300', onChange: () => emit('toggle', product.id) }),
                product.image ? h('img', { src: product.image, alt: '', class: 'h-10 w-10 rounded-lg object-cover' }) : h('span', { class: 'grid h-10 w-10 place-items-center rounded-lg bg-slate-100 text-slate-400' }, '□'),
                h('span', { class: 'min-w-0 flex-1 truncate text-sm font-medium text-slate-800' }, product.title),
            ])) : [h('p', { class: 'p-5 text-center text-sm text-slate-400' }, '没有匹配的商品')]),
        ]);
    },
});

const props = defineProps<{
    discounts: { data: DiscountRow[]; next_cursor: string | null };
    filters: { status: string };
    connection: { ready: boolean; code: string | null; message: string | null };
    store: { id: number; name: string; currency: string; timezone: string };
    products: ProductOption[];
    permissions: { manage: boolean };
}>();

const rows = ref([...props.discounts.data]);
const search = ref('');
const typeDialog = ref(false);
const editorOpen = ref(false);
const saving = ref(false);
const error = ref('');
const productSearch = ref('');
const editingId = ref('');

const kindOptions: Array<{ value: Kind; title: string; description: string; icon: string }> = [
    { value: 'product_amount', title: '产品金额减免', description: '为指定产品提供百分比或固定金额折扣', icon: '◇' },
    { value: 'bxgy', title: '买 X 送 Y', description: '购买指定数量后，另一组产品享受折扣或免费', icon: '⇄' },
    { value: 'order_amount', title: '订单金额减免', description: '针对整个订单提供百分比或固定金额折扣', icon: '▣' },
    { value: 'free_shipping', title: '免运费', description: '满足条件的订单免除运费', icon: '→' },
];

const nowLocal = () => {
    const date = new Date(Date.now() - new Date().getTimezoneOffset() * 60000);
    return date.toISOString().slice(0, 16);
};
const blankForm = (kind: Kind = 'product_amount') => ({
    idempotency_key: requestId(),
    kind,
    title: '',
    code: '',
    starts_at: nowLocal(),
    ends_at: '',
    usage_limit: '' as string | number,
    applies_once_per_customer: false,
    combine_order: false,
    combine_product: false,
    combine_shipping: false,
    minimum_type: 'none' as 'none' | 'subtotal' | 'quantity',
    minimum_subtotal: '' as string | number,
    minimum_quantity: '' as string | number,
    value_type: 'percentage' as 'percentage' | 'fixed_amount',
    value: 10 as string | number,
    product_ids: [] as string[],
    buys_product_ids: [] as string[],
    gets_product_ids: [] as string[],
    buys_quantity: 1 as string | number,
    gets_quantity: 1 as string | number,
    gets_percentage: 100 as string | number,
    uses_per_order_limit: '' as string | number,
});
const form = reactive(blankForm());

const visibleRows = computed(() => {
    const needle = search.value.trim().toLowerCase();
    if (!needle) return rows.value;
    return rows.value.filter(row => [row.title, row.summary, ...row.codes].some(value => value.toLowerCase().includes(needle)));
});
const visibleProducts = computed(() => {
    const needle = productSearch.value.trim().toLowerCase();
    return needle ? props.products.filter(product => product.title.toLowerCase().includes(needle)) : props.products;
});
const selectedKind = computed(() => kindOptions.find(option => option.value === form.kind));

function resetForm(kind: Kind) {
    Object.assign(form, blankForm(kind));
    editingId.value = '';
    error.value = '';
    productSearch.value = '';
}
function chooseKind(kind: Kind) {
    resetForm(kind);
    typeDialog.value = false;
    editorOpen.value = true;
}
function openCreate() {
    if (!props.permissions.manage || !props.connection.ready) return;
    typeDialog.value = true;
}
function edit(row: DiscountRow) {
    if (!row.editable || !props.permissions.manage) return;
    const kind = row.kind as Kind;
    resetForm(kind);
    editingId.value = row.id;
    Object.assign(form, {
        kind,
        title: row.title,
        code: row.codes[0] ?? '',
        starts_at: localDateTime(row.starts_at),
        ends_at: localDateTime(row.ends_at),
        usage_limit: row.usage_limit ?? '',
        applies_once_per_customer: row.applies_once_per_customer,
        combine_order: row.combines_with.orderDiscounts ?? false,
        combine_product: row.combines_with.productDiscounts ?? false,
        combine_shipping: row.combines_with.shippingDiscounts ?? false,
        minimum_type: row.minimum_type,
        minimum_subtotal: row.minimum_subtotal ?? '',
        minimum_quantity: row.minimum_quantity ?? '',
        value_type: row.value_type ?? 'percentage',
        value: row.value ?? 10,
        product_ids: [...row.product_ids],
        buys_product_ids: [...row.buys_product_ids],
        gets_product_ids: [...row.gets_product_ids],
        buys_quantity: row.buys_quantity ?? 1,
        gets_quantity: row.gets_quantity ?? 1,
        gets_percentage: row.gets_percentage ?? 100,
        uses_per_order_limit: row.uses_per_order_limit ?? '',
    });
    editorOpen.value = true;
}
function localDateTime(value: string | null) {
    if (!value) return '';
    const date = new Date(value);
    return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
}
function numericId(gid: string) { return gid.split('/').pop() ?? ''; }
function requestId() { return globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(16).slice(2)}`; }
function csrf() { return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''; }
async function requestJson<T>(url: string, options: RequestInit): Promise<T> {
    const response = await fetch(url, {
        ...options,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), ...(options.headers ?? {}) },
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        const validation = payload.errors ? Object.values(payload.errors).flat().join('；') : '';
        throw new Error(validation || payload.error?.message || 'Shopify 未能保存折扣。');
    }
    return payload.data as T;
}
async function save() {
    if (saving.value) return;
    error.value = '';
    if (!form.title.trim() || !form.code.trim()) { error.value = '请填写折扣标题和折扣码。'; return; }
    if (form.kind === 'product_amount' && !form.product_ids.length) { error.value = '请选择参与折扣的产品。'; return; }
    if (form.kind === 'bxgy' && (!form.buys_product_ids.length || !form.gets_product_ids.length)) { error.value = '请选择购买产品和优惠产品。'; return; }
    saving.value = true;
    try {
        const payload = { ...form };
        const id = editingId.value ? numericId(editingId.value) : '';
        const saved = await requestJson<DiscountRow>(id ? `/discounts/${id}` : '/discounts', {
            method: id ? 'PUT' : 'POST',
            body: JSON.stringify(payload),
        });
        const index = rows.value.findIndex(row => row.id === saved.id);
        if (index >= 0) rows.value.splice(index, 1, saved); else rows.value.unshift(saved);
        editorOpen.value = false;
    } catch (exception) {
        error.value = exception instanceof Error ? exception.message : 'Shopify 未能保存折扣。';
    } finally {
        saving.value = false;
    }
}
function setStatus(status: string) {
    router.get('/discounts', status ? { status } : {}, { preserveState: false, replace: true });
}
function connectApp() { router.post('/discounts/connect'); }
function statusLabel(status: string) { return ({ active: '有效', scheduled: '已计划', expired: '已过期' } as Record<string, string>)[status] ?? '未知'; }
function statusClass(status: string) {
    return status === 'active' ? 'bg-emerald-100 text-emerald-800' : status === 'scheduled' ? 'bg-sky-100 text-sky-800' : status === 'expired' ? 'bg-slate-100 text-slate-600' : 'bg-amber-100 text-amber-800';
}
function kindLabel(kind: DiscountRow['kind']) { return ({ product_amount: '产品金额减免', order_amount: '订单金额减免', bxgy: '买 X 送 Y', free_shipping: '免运费', app: '应用折扣' } as Record<string, string>)[kind] ?? '其他'; }
function formatDate(value: string | null) { return value ? new Intl.DateTimeFormat('zh-CN', { month: 'numeric', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : '无结束时间'; }
function toggleProduct(field: 'product_ids' | 'buys_product_ids' | 'gets_product_ids', id: string) {
    const values = form[field];
    const index = values.indexOf(id);
    if (index >= 0) values.splice(index, 1); else values.push(id);
}
function closeTopDialog() {
    if (saving.value) return;
    if (editorOpen.value) editorOpen.value = false;
    else if (typeDialog.value) typeDialog.value = false;
}
function onKeydown(event: KeyboardEvent) { if (event.key === 'Escape') closeTopDialog(); }
onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Head title="折扣管理" />
    <AppLayout :breadcrumbs="[{ label: '工作台', href: '/dashboard' }, { label: '业务中心' }, { label: '折扣管理' }]">
        <div class="mx-auto max-w-[1600px] space-y-5">
            <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold text-emerald-700">{{ store.name }} · {{ store.currency }}</p>
                    <h1 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">折扣管理</h1>
                    <p class="mt-2 text-sm text-slate-500">直接查看、创建和修改当前店铺的 Shopify 折扣码。</p>
                </div>
                <button v-if="permissions.manage" type="button" :disabled="!connection.ready" class="h-11 rounded-xl bg-slate-950 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-40" @click="openCreate">＋ 创建折扣</button>
            </header>

            <section v-if="!connection.ready" class="flex flex-col gap-4 rounded-2xl border border-amber-200 bg-amber-50 p-5 sm:flex-row sm:items-center sm:justify-between">
                <div><h2 class="font-semibold text-amber-950">折扣管理 App 尚未连接</h2><p class="mt-1 text-sm text-amber-800">{{ connection.message }}</p></div>
                <div class="flex items-center gap-3"><span class="rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-amber-800">当前店铺：{{ store.name }}</span><button v-if="permissions.manage" type="button" class="rounded-xl bg-amber-950 px-4 py-2 text-sm font-semibold text-white" @click="connectApp">连接 Shopify</button></div>
            </section>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-col gap-3 border-b border-slate-200 p-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex flex-wrap gap-2">
                        <button v-for="option in [{value:'',label:'全部'},{value:'active',label:'有效'},{value:'scheduled',label:'已计划'},{value:'expired',label:'已过期'}]" :key="option.value" type="button" class="rounded-lg px-3 py-2 text-sm font-semibold transition" :class="filters.status === option.value ? 'bg-slate-950 text-white' : 'bg-slate-50 text-slate-600 hover:bg-slate-100'" @click="setStatus(option.value)">{{ option.label }}</button>
                    </div>
                    <label class="relative w-full lg:max-w-sm"><span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400">⌕</span><input v-model="search" type="search" class="h-10 w-full rounded-xl border border-slate-200 bg-slate-50 pr-4 pl-9 text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100" placeholder="搜索标题或折扣码"></label>
                </div>

                <div v-if="visibleRows.length" class="overflow-x-auto">
                    <table class="w-full min-w-[1080px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs font-semibold text-slate-500"><tr><th class="px-5 py-4">标题</th><th class="px-5 py-4">状态</th><th class="px-5 py-4">方式</th><th class="px-5 py-4">折扣类型</th><th class="px-5 py-4">使用次数</th><th class="px-5 py-4">结束时间</th><th class="px-5 py-4 text-right">操作</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="row in visibleRows" :key="row.id" class="transition hover:bg-slate-50">
                                <td class="max-w-sm px-5 py-4"><p class="font-semibold text-slate-950">{{ row.title }}</p><p class="mt-1 truncate text-xs text-slate-500">{{ row.codes.join('、') || '应用自动管理' }}<span v-if="row.summary"> · {{ row.summary }}</span></p></td>
                                <td class="px-5 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="statusClass(row.status)">{{ statusLabel(row.status) }}</span></td>
                                <td class="px-5 py-4 text-slate-600">折扣码</td>
                                <td class="px-5 py-4 text-slate-700">{{ kindLabel(row.kind) }}</td>
                                <td class="px-5 py-4 font-semibold text-slate-700">{{ row.usage_count.toLocaleString() }}<span v-if="row.usage_limit" class="font-normal text-slate-400"> / {{ row.usage_limit.toLocaleString() }}</span></td>
                                <td class="px-5 py-4 text-xs text-slate-500">{{ formatDate(row.ends_at) }}</td>
                                <td class="px-5 py-4 text-right"><button v-if="permissions.manage && row.editable" type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:border-slate-300 hover:bg-white" @click="edit(row)">编辑</button><span v-else class="text-xs text-slate-400">{{ row.editable ? '只读' : '请在 Shopify 修改' }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="grid min-h-72 place-items-center p-8 text-center"><div><div class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-emerald-50 text-2xl text-emerald-700">%</div><h2 class="mt-4 font-semibold text-slate-900">{{ connection.ready ? '暂无折扣' : '等待 Shopify 授权' }}</h2><p class="mt-2 text-sm text-slate-500">{{ connection.ready ? '创建第一条折扣后会显示在这里。' : '连接折扣管理 App 后即可读取当前店铺折扣。' }}</p></div></div>
                <button v-if="discounts.next_cursor" type="button" class="m-4 rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700" @click="router.get('/discounts', { status: filters.status || undefined, cursor: discounts.next_cursor })">加载更多</button>
            </section>
        </div>

        <div v-if="typeDialog" class="fixed inset-0 z-[120] grid place-items-center bg-slate-950/55 p-4" role="dialog" aria-modal="true" aria-labelledby="discount-type-title" @mousedown.self="typeDialog = false">
            <div class="w-full max-w-2xl overflow-hidden rounded-2xl bg-white shadow-2xl">
                <header class="flex items-center justify-between border-b border-slate-200 px-6 py-5"><div><h2 id="discount-type-title" class="text-xl font-semibold">选择折扣类型</h2><p class="mt-1 text-sm text-slate-500">创建后会直接同步到当前 Shopify 店铺。</p></div><button type="button" class="grid h-9 w-9 place-items-center rounded-full text-2xl text-slate-400 hover:bg-slate-100" @click="typeDialog = false">×</button></header>
                <div class="divide-y divide-slate-100 py-2"><button v-for="option in kindOptions" :key="option.value" type="button" class="flex w-full items-center gap-4 px-6 py-5 text-left transition hover:bg-slate-50" @click="chooseKind(option.value)"><span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-slate-100 text-xl font-bold text-slate-700">{{ option.icon }}</span><span class="min-w-0 flex-1"><strong class="text-base text-slate-950">{{ option.title }}</strong><span class="mt-1 block text-sm text-slate-500">{{ option.description }}</span></span><span class="text-2xl text-slate-400">›</span></button></div>
            </div>
        </div>

        <div v-if="editorOpen" class="fixed inset-0 z-[125] flex justify-end bg-slate-950/45" role="dialog" aria-modal="true" aria-labelledby="discount-editor-title" @mousedown.self="closeTopDialog">
            <form class="flex h-full w-full max-w-3xl flex-col bg-slate-50 shadow-2xl" @submit.prevent="save">
                <header class="flex items-center justify-between border-b border-slate-200 bg-white px-5 py-4 sm:px-7"><div><p class="text-xs font-semibold uppercase tracking-wider text-emerald-700">{{ editingId ? '修改 Shopify 折扣' : '创建 Shopify 折扣' }}</p><h2 id="discount-editor-title" class="mt-1 text-xl font-semibold text-slate-950">{{ selectedKind?.title }}</h2></div><button type="button" class="grid h-10 w-10 place-items-center rounded-full text-2xl text-slate-400 hover:bg-slate-100" @click="closeTopDialog">×</button></header>
                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto p-5 sm:p-7">
                    <p v-if="error" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">{{ error }}</p>
                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h3 class="font-semibold text-slate-950">基本信息</h3><div class="mt-4 grid gap-4 sm:grid-cols-2"><label class="text-sm font-medium text-slate-700">标题<input v-model="form.title" required maxlength="120" class="mt-1.5 h-11 w-full rounded-xl border-slate-300" placeholder="例如：劳动节满减"></label><label class="text-sm font-medium text-slate-700">折扣码<input v-model.trim="form.code" required maxlength="40" class="mt-1.5 h-11 w-full rounded-xl border-slate-300 uppercase" placeholder="LABORDAY50"></label></div></section>

                    <section v-if="form.kind === 'product_amount' || form.kind === 'order_amount'" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h3 class="font-semibold text-slate-950">折扣值</h3><div class="mt-4 grid gap-4 sm:grid-cols-[180px_1fr]"><select v-model="form.value_type" class="h-11 rounded-xl border-slate-300"><option value="percentage">百分比</option><option value="fixed_amount">固定金额</option></select><label class="relative"><input v-model="form.value" required type="number" min="0.01" :max="form.value_type === 'percentage' ? 100 : 999999999" step="0.01" class="h-11 w-full rounded-xl border-slate-300 pr-14"><span class="absolute inset-y-0 right-4 flex items-center text-sm font-semibold text-slate-500">{{ form.value_type === 'percentage' ? '%' : store.currency }}</span></label></div></section>

                    <section v-if="form.kind === 'product_amount'" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><ProductPickerTitle title="参与折扣的产品" :count="form.product_ids.length" /><ProductPicker v-model:search="productSearch" :products="visibleProducts" :selected="form.product_ids" @toggle="toggleProduct('product_ids', $event)" /></section>
                    <section v-if="form.kind === 'bxgy'" class="space-y-5"><div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><ProductPickerTitle title="客户购买" :count="form.buys_product_ids.length" /><label class="mt-4 block max-w-xs text-sm font-medium text-slate-700">购买数量<input v-model="form.buys_quantity" type="number" min="1" max="1000" class="mt-1.5 h-11 w-full rounded-xl border-slate-300"></label><ProductPicker v-model:search="productSearch" :products="visibleProducts" :selected="form.buys_product_ids" @toggle="toggleProduct('buys_product_ids', $event)" /></div><div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><ProductPickerTitle title="客户获得" :count="form.gets_product_ids.length" /><div class="mt-4 grid gap-4 sm:grid-cols-2"><label class="text-sm font-medium text-slate-700">优惠数量<input v-model="form.gets_quantity" type="number" min="1" max="1000" class="mt-1.5 h-11 w-full rounded-xl border-slate-300"></label><label class="text-sm font-medium text-slate-700">优惠百分比<input v-model="form.gets_percentage" type="number" min="0.01" max="100" step="0.01" class="mt-1.5 h-11 w-full rounded-xl border-slate-300"><span class="mt-1 block text-xs text-slate-400">100% 表示免费</span></label></div><ProductPicker v-model:search="productSearch" :products="visibleProducts" :selected="form.gets_product_ids" @toggle="toggleProduct('gets_product_ids', $event)" /></div></section>

                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h3 class="font-semibold text-slate-950">最低购买要求</h3><div class="mt-4 grid gap-4 sm:grid-cols-2"><select v-model="form.minimum_type" class="h-11 rounded-xl border-slate-300"><option value="none">无最低要求</option><option value="subtotal">最低购买金额</option><option value="quantity">最低商品数量</option></select><label v-if="form.minimum_type === 'subtotal'" class="relative"><input v-model="form.minimum_subtotal" type="number" min="0.01" step="0.01" required class="h-11 w-full rounded-xl border-slate-300 pr-16"><span class="absolute inset-y-0 right-4 flex items-center text-sm text-slate-500">{{ store.currency }}</span></label><input v-if="form.minimum_type === 'quantity'" v-model="form.minimum_quantity" type="number" min="1" required class="h-11 rounded-xl border-slate-300"></div></section>

                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h3 class="font-semibold text-slate-950">有效时间</h3><div class="mt-4 grid gap-4 sm:grid-cols-2"><label class="text-sm font-medium text-slate-700">开始时间<input v-model="form.starts_at" required type="datetime-local" class="mt-1.5 h-11 w-full rounded-xl border-slate-300"></label><label class="text-sm font-medium text-slate-700">结束时间（可选）<input v-model="form.ends_at" type="datetime-local" class="mt-1.5 h-11 w-full rounded-xl border-slate-300"></label></div><p class="mt-3 text-xs text-slate-400">时间按照当前店铺时区 {{ store.timezone }} 保存到 Shopify。</p></section>

                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h3 class="font-semibold text-slate-950">使用限制</h3><div class="mt-4 grid gap-4 sm:grid-cols-2"><label class="text-sm font-medium text-slate-700">总使用次数上限（可选）<input v-model="form.usage_limit" type="number" min="1" class="mt-1.5 h-11 w-full rounded-xl border-slate-300"></label><label v-if="form.kind === 'bxgy'" class="text-sm font-medium text-slate-700">每个订单最多使用（可选）<input v-model="form.uses_per_order_limit" type="number" min="1" max="1000" class="mt-1.5 h-11 w-full rounded-xl border-slate-300"></label></div><label class="mt-4 flex items-center gap-3 text-sm text-slate-700"><input v-model="form.applies_once_per_customer" type="checkbox" class="rounded border-slate-300 text-slate-950">每位客户限用一次</label></section>

                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h3 class="font-semibold text-slate-950">组合方式</h3><div class="mt-4 grid gap-3 sm:grid-cols-3"><label class="flex items-center gap-2 rounded-xl border border-slate-200 p-3 text-sm"><input v-model="form.combine_product" type="checkbox" class="rounded">产品折扣</label><label class="flex items-center gap-2 rounded-xl border border-slate-200 p-3 text-sm"><input v-model="form.combine_order" type="checkbox" class="rounded">订单折扣</label><label class="flex items-center gap-2 rounded-xl border border-slate-200 p-3 text-sm"><input v-model="form.combine_shipping" type="checkbox" class="rounded">运费折扣</label></div></section>
                </div>
                <footer class="flex items-center justify-end gap-3 border-t border-slate-200 bg-white px-5 py-4 sm:px-7"><button type="button" class="h-11 rounded-xl border border-slate-200 px-5 text-sm font-semibold text-slate-700" :disabled="saving" @click="closeTopDialog">取消</button><button class="h-11 rounded-xl bg-slate-950 px-6 text-sm font-semibold text-white disabled:cursor-wait disabled:opacity-50" :disabled="saving">{{ saving ? '保存到 Shopify…' : (editingId ? '保存修改' : '创建折扣') }}</button></footer>
            </form>
        </div>
    </AppLayout>
</template>
