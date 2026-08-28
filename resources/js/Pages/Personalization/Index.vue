<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';

type Algorithm = 'manual' | 'best_seller' | 'new_arrivals' | 'frequently_bought_together' | 'recently_viewed' | 'similar_products';
type Placement = 'homepage' | 'product_page' | 'cart_page' | 'smart_cart';
type ComponentStatus = 'draft' | 'active' | 'disabled';
type Tab = 'overview' | 'strategies' | 'components' | 'smart-cart' | 'analytics';

interface Rule { type: string; value: Record<string, unknown>; enabled: boolean }
interface ProductOverride { shopify_product_id: string; type: 'manual' | 'pinned' | 'excluded'; position: number }
interface Strategy {
    uuid: string;
    name: string;
    algorithm: Algorithm;
    enabled: boolean;
    item_limit: number;
    rules: Rule[];
    product_overrides: ProductOverride[];
}
interface Style {
    layout: 'carousel' | 'grid';
    desktop_columns: number;
    mobile_columns: number;
    show_image: boolean;
    show_vendor: boolean;
    show_price: boolean;
    show_compare_at_price: boolean;
    show_add_to_cart: boolean;
    tokens: Record<string, string | number>;
}
interface Component {
    uuid: string;
    strategy_uuid: string;
    strategy_name: string;
    name: string;
    placement: Placement;
    status: ComponentStatus;
    heading: string | null;
    button_label: string | null;
    published_at: string | null;
    style: Style;
}
interface ProductOption {
    shopify_product_id: string;
    shopify_gid: string;
    title: string;
    handle: string;
    image_url: string | null;
    price: string | null;
    currency: string;
    tags: string[];
}
interface PreviewProduct {
    shopify_product_id: string;
    title: string;
    vendor: string | null;
    price: { currency: string; minimum: string | null; maximum: string | null };
    storefront: { image: { url: string | null; alt: string | null }; path: string };
    reason_code: string;
}

const props = defineProps<{
    organization: { id: number; name: string };
    store: { id: number; name: string; shopify_domain: string; currency: string };
    strategies: Strategy[];
    components: Component[];
    smartCart: { uuid: string; strategy_uuid: string | null; enabled: boolean; compatibility_status: string; fallback_mode: string; settings: Record<string, unknown> } | null;
    products: ProductOption[];
    options: { algorithms: Array<{ value: Algorithm; label: string }>; placements: Array<{ value: Placement; label: string }> };
    permissions: { manage: boolean; manageSmartCart: boolean; viewAnalytics: boolean };
    analytics: { status: string; impressions: number; clicks: number; add_to_carts: number; orders: number; attributed_revenue: string; aov: string };
}>();

const baseUrl = `/organizations/${props.organization.id}/stores/${props.store.id}/personalization`;
const tabs: Array<{ value: Tab; label: string; description: string }> = [
    { value: 'overview', label: '概览', description: '查看配置进度' },
    { value: 'strategies', label: '推荐策略', description: '算法、规则与商品' },
    { value: 'components', label: '推荐组件', description: '位置、样式与预览' },
    { value: 'smart-cart', label: 'Smart Cart', description: '安全草稿与兼容性' },
    { value: 'analytics', label: '分析', description: '曝光与归因结果' },
];
const activeTab = ref<Tab>('overview');
const algorithmLabel = (value: Algorithm) => props.options.algorithms.find(option => option.value === value)?.label ?? value;
const placementLabel = (value: Placement) => props.options.placements.find(option => option.value === value)?.label ?? value;
const statusLabel = (status: ComponentStatus) => ({ draft: '草稿', active: '后端已启用', disabled: '已停用' }[status]);

const createStrategyForm = useForm({ name: '', algorithm: 'manual' as Algorithm, item_limit: 8 });
const createStrategy = () => createStrategyForm.post(`${baseUrl}/strategies`, {
    preserveScroll: true,
    onSuccess: () => createStrategyForm.reset(),
});

const selectedStrategyUuid = ref(props.strategies[0]?.uuid ?? '');
const selectedStrategy = computed(() => props.strategies.find(strategy => strategy.uuid === selectedStrategyUuid.value) ?? null);
const editStrategyForm = useForm({ name: '', algorithm: 'manual' as Algorithm, item_limit: 8 });
const ruleForm = useForm({
    include_tags_text: '',
    exclude_tags_text: '',
    minimum_price: '' as string | number,
    maximum_price: '' as string | number,
    minimum_inventory: '' as string | number,
    in_stock_only: true,
});
const productForm = useForm({ manual: [] as string[], pinned: [] as string[], excluded: [] as string[] });
const productFields: Array<{ key: 'manual' | 'pinned' | 'excluded'; label: string }> = [
    { key: 'manual', label: '手动推荐' },
    { key: 'pinned', label: '置顶商品' },
    { key: 'excluded', label: '排除商品' },
];
const splitTags = (value: string) => value.split(/[\n,]/).map(tag => tag.trim()).filter(Boolean);
const hydrateStrategyForms = () => {
    const strategy = selectedStrategy.value;
    if (!strategy) return;
    editStrategyForm.name = strategy.name;
    editStrategyForm.algorithm = strategy.algorithm;
    editStrategyForm.item_limit = strategy.item_limit;
    const rule = (type: string) => strategy.rules.find(item => item.type === type)?.value ?? {};
    ruleForm.include_tags_text = ((rule('include_tags').tags as string[] | undefined) ?? []).join(', ');
    ruleForm.exclude_tags_text = ((rule('exclude_tags').tags as string[] | undefined) ?? []).join(', ');
    ruleForm.minimum_price = (rule('minimum_price').amount as string | undefined) ?? '';
    ruleForm.maximum_price = (rule('maximum_price').amount as string | undefined) ?? '';
    ruleForm.minimum_inventory = (rule('minimum_inventory').quantity as number | undefined) ?? '';
    ruleForm.in_stock_only = (rule('in_stock_only').enabled as boolean | undefined) ?? true;
    productForm.manual = strategy.product_overrides.filter(item => item.type === 'manual').map(item => `gid://shopify/Product/${item.shopify_product_id}`);
    productForm.pinned = strategy.product_overrides.filter(item => item.type === 'pinned').map(item => `gid://shopify/Product/${item.shopify_product_id}`);
    productForm.excluded = strategy.product_overrides.filter(item => item.type === 'excluded').map(item => `gid://shopify/Product/${item.shopify_product_id}`);
    editStrategyForm.clearErrors();
    ruleForm.clearErrors();
    productForm.clearErrors();
};
watch(selectedStrategyUuid, hydrateStrategyForms);
onMounted(hydrateStrategyForms);
const updateStrategy = () => selectedStrategy.value && editStrategyForm.put(`${baseUrl}/strategies/${selectedStrategy.value.uuid}`, { preserveScroll: true });
const updateRules = () => selectedStrategy.value && ruleForm
    .transform(data => ({
        include_tags: splitTags(data.include_tags_text),
        exclude_tags: splitTags(data.exclude_tags_text),
        minimum_price: data.minimum_price === '' ? null : data.minimum_price,
        maximum_price: data.maximum_price === '' ? null : data.maximum_price,
        minimum_inventory: data.minimum_inventory === '' ? null : data.minimum_inventory,
        in_stock_only: data.in_stock_only,
    }))
    .put(`${baseUrl}/strategies/${selectedStrategy.value.uuid}/rules`, { preserveScroll: true });
const updateProducts = () => selectedStrategy.value && productForm.put(`${baseUrl}/strategies/${selectedStrategy.value.uuid}/products`, { preserveScroll: true });

const createComponentForm = useForm({
    strategy_uuid: props.strategies[0]?.uuid ?? '',
    name: '',
    placement: 'product_page' as Placement,
    heading: '你可能还喜欢',
    button_label: '加入购物车',
});
const createComponent = () => createComponentForm.post(`${baseUrl}/components`, {
    preserveScroll: true,
    onSuccess: () => createComponentForm.reset('name'),
});
const selectedComponentUuid = ref(props.components[0]?.uuid ?? '');
const selectedComponent = computed(() => props.components.find(component => component.uuid === selectedComponentUuid.value) ?? null);
const componentForm = useForm({ strategy_uuid: '', name: '', placement: 'product_page' as Placement, heading: '', button_label: '' });
const styleForm = useForm({
    layout: 'carousel' as 'carousel' | 'grid',
    desktop_columns: 4,
    mobile_columns: 2,
    show_image: true,
    show_vendor: false,
    show_price: true,
    show_compare_at_price: true,
    show_add_to_cart: true,
    tokens: { text_color: '#111827', background_color: '#FFFFFF', button_color: '#111827', button_text_color: '#FFFFFF', border_radius: 12, gap: 16 },
});
const hydrateComponentForms = () => {
    const component = selectedComponent.value;
    if (!component) return;
    componentForm.strategy_uuid = component.strategy_uuid;
    componentForm.name = component.name;
    componentForm.placement = component.placement;
    componentForm.heading = component.heading ?? '';
    componentForm.button_label = component.button_label ?? '';
    styleForm.layout = component.style.layout;
    styleForm.desktop_columns = component.style.desktop_columns;
    styleForm.mobile_columns = component.style.mobile_columns;
    styleForm.show_image = component.style.show_image;
    styleForm.show_vendor = component.style.show_vendor;
    styleForm.show_price = component.style.show_price;
    styleForm.show_compare_at_price = component.style.show_compare_at_price;
    styleForm.show_add_to_cart = component.style.show_add_to_cart;
    styleForm.tokens = {
        text_color: String(component.style.tokens.text_color ?? '#111827'),
        background_color: String(component.style.tokens.background_color ?? '#FFFFFF'),
        button_color: String(component.style.tokens.button_color ?? '#111827'),
        button_text_color: String(component.style.tokens.button_text_color ?? '#FFFFFF'),
        border_radius: Number(component.style.tokens.border_radius ?? 12),
        gap: Number(component.style.tokens.gap ?? 16),
    };
    void loadPreview();
};
watch(selectedComponentUuid, hydrateComponentForms);
const updateComponent = () => selectedComponent.value && componentForm.put(`${baseUrl}/components/${selectedComponent.value.uuid}`, { preserveScroll: true });
const updateStyle = () => selectedComponent.value && styleForm.put(`${baseUrl}/components/${selectedComponent.value.uuid}/style`, {
    preserveScroll: true,
    onSuccess: () => void loadPreview(),
});
const activateComponent = (component: Component) => router.post(`${baseUrl}/components/${component.uuid}/activate`, {}, { preserveScroll: true });
const disableComponent = (component: Component) => router.post(`${baseUrl}/components/${component.uuid}/disable`, {}, { preserveScroll: true });

const previewMode = ref<'desktop' | 'mobile'>('desktop');
const previewItems = ref<PreviewProduct[]>([]);
const previewLoading = ref(false);
const previewError = ref('');
async function loadPreview() {
    const component = selectedComponent.value;
    if (!component) return;
    previewLoading.value = true;
    previewError.value = '';
    const params = new URLSearchParams();
    if (props.products[0]) {
        params.set('seed_product_id', props.products[0].shopify_product_id);
        params.append('recently_viewed_product_ids[]', props.products[0].shopify_product_id);
    }
    try {
        const response = await fetch(`${baseUrl}/components/${component.uuid}/preview?${params.toString()}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        const payload = await response.json();
        if (!response.ok) throw new Error(payload?.error?.message ?? '无法生成预览。');
        previewItems.value = payload.data.items ?? [];
    } catch (error) {
        previewItems.value = [];
        previewError.value = error instanceof Error ? error.message : '无法生成预览。';
    } finally {
        previewLoading.value = false;
    }
}
onMounted(() => void loadPreview());
const previewColumns = computed(() => previewMode.value === 'desktop' ? styleForm.desktop_columns : styleForm.mobile_columns);
const money = (value: string | null, currency: string) => value === null ? '—' : new Intl.NumberFormat('zh-CN', { style: 'currency', currency }).format(Number(value));

const smartCartForm = useForm({
    strategy_uuid: props.smartCart?.strategy_uuid ?? '',
    heading: String(props.smartCart?.settings?.heading ?? '购物车推荐'),
});
const saveSmartCartDraft = () => smartCartForm.put(`${baseUrl}/smart-cart`, { preserveScroll: true });
const analyticsCards = computed(() => [
    ['曝光', props.analytics.impressions.toLocaleString()],
    ['点击', props.analytics.clicks.toLocaleString()],
    ['加购', props.analytics.add_to_carts.toLocaleString()],
    ['订单', props.analytics.orders.toLocaleString()],
    ['归因收入', money(props.analytics.attributed_revenue, props.store.currency)],
    ['AOV', money(props.analytics.aov, props.store.currency)],
]);
</script>

<template>
    <Head title="个性化推荐" />
    <AppLayout>
        <div class="space-y-6">
            <header class="rounded-2xl bg-gradient-to-br from-slate-950 via-slate-900 to-indigo-950 px-6 py-7 text-white shadow-sm">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-indigo-300">Deco 个性化推荐</p>
                        <h1 class="mt-2 text-2xl font-semibold">{{ store.name }} 的推荐工作台</h1>
                        <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">复用 Commerce Hub 商品、库存与订单信号。先保存草稿并预览，再启用后端配置；主题展示将在 Test 联调阶段单独开启。</p>
                    </div>
                    <div class="grid grid-cols-3 gap-2 text-center text-xs">
                        <div class="rounded-xl bg-white/10 px-4 py-3"><strong class="block text-xl">{{ strategies.length }}</strong>策略</div>
                        <div class="rounded-xl bg-white/10 px-4 py-3"><strong class="block text-xl">{{ components.length }}</strong>组件</div>
                        <div class="rounded-xl bg-white/10 px-4 py-3"><strong class="block text-xl">{{ components.filter(item => item.status === 'active').length }}</strong>已启用</div>
                    </div>
                </div>
            </header>

            <nav class="grid gap-2 rounded-2xl border border-slate-200 bg-white p-2 shadow-sm md:grid-cols-5" aria-label="个性化推荐页面">
                <button v-for="tab in tabs" :key="tab.value" type="button" class="rounded-xl px-4 py-3 text-left transition" :class="activeTab === tab.value ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-50'" @click="activeTab = tab.value">
                    <span class="block text-sm font-semibold">{{ tab.label }}</span>
                    <span class="mt-1 block text-xs" :class="activeTab === tab.value ? 'text-slate-300' : 'text-slate-400'">{{ tab.description }}</span>
                </button>
            </nav>

            <section v-if="activeTab === 'overview'" class="grid gap-5 lg:grid-cols-[1.15fr_.85fr]">
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-slate-900">上线检查</h2>
                    <div class="mt-5 space-y-3">
                        <div v-for="item in [
                            { done: strategies.length > 0, label: '创建至少一个推荐策略' },
                            { done: components.length > 0, label: '创建首页、商品页或购物车组件' },
                            { done: components.some(component => component.status === 'active'), label: '完成桌面与移动预览并启用后端配置' },
                            { done: false, label: '在 Test 主题中添加 App Block（后续阶段）' },
                        ]" :key="item.label" class="flex items-center gap-3 rounded-xl border border-slate-100 px-4 py-3">
                            <span class="flex size-7 items-center justify-center rounded-full text-sm font-bold" :class="item.done ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-400'">{{ item.done ? '✓' : '·' }}</span>
                            <span class="text-sm text-slate-700">{{ item.label }}</span>
                        </div>
                    </div>
                </div>
                <div class="rounded-2xl border border-amber-200 bg-amber-50 p-6">
                    <p class="text-sm font-semibold text-amber-900">安全状态</p>
                    <h2 class="mt-2 text-xl font-semibold text-slate-900">Smart Cart 默认关闭</h2>
                    <p class="mt-3 text-sm leading-6 text-slate-600">当前只能保存草稿。兼容性检查、主题预览和人工启用完成前，系统始终使用 Shopify 默认购物车。</p>
                    <button type="button" class="mt-5 rounded-lg border border-amber-300 bg-white px-4 py-2 text-sm font-medium text-amber-900" @click="activeTab = 'smart-cart'">查看 Smart Cart 草稿</button>
                </div>
            </section>

            <section v-else-if="activeTab === 'strategies'" class="grid gap-5 xl:grid-cols-[320px_1fr]">
                <div class="space-y-5">
                    <form v-if="permissions.manage" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" @submit.prevent="createStrategy">
                        <h2 class="font-semibold text-slate-900">新建推荐策略</h2>
                        <label class="mt-4 block text-sm font-medium text-slate-700">策略名称<input v-model="createStrategyForm.name" class="mt-1 w-full rounded-lg border-slate-300" required maxlength="80"></label>
                        <label class="mt-3 block text-sm font-medium text-slate-700">算法<select v-model="createStrategyForm.algorithm" class="mt-1 w-full rounded-lg border-slate-300"><option v-for="option in options.algorithms" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
                        <label class="mt-3 block text-sm font-medium text-slate-700">推荐数量<input v-model.number="createStrategyForm.item_limit" type="number" min="1" max="50" class="mt-1 w-full rounded-lg border-slate-300"></label>
                        <button class="mt-4 w-full rounded-lg bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50" :disabled="createStrategyForm.processing">创建草稿</button>
                    </form>
                    <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                        <p class="px-2 py-2 text-xs font-semibold uppercase tracking-wider text-slate-400">策略列表</p>
                        <button v-for="strategy in strategies" :key="strategy.uuid" type="button" class="mb-1 w-full rounded-xl px-3 py-3 text-left" :class="selectedStrategyUuid === strategy.uuid ? 'bg-indigo-50 text-indigo-900' : 'hover:bg-slate-50'" @click="selectedStrategyUuid = strategy.uuid">
                            <span class="block text-sm font-semibold">{{ strategy.name }}</span><span class="mt-1 block text-xs text-slate-500">{{ algorithmLabel(strategy.algorithm) }} · {{ strategy.item_limit }} 件</span>
                        </button>
                        <p v-if="strategies.length === 0" class="px-3 py-6 text-center text-sm text-slate-400">尚未创建策略</p>
                    </div>
                </div>

                <div v-if="selectedStrategy" class="space-y-5">
                    <form class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" @submit.prevent="updateStrategy">
                        <div class="flex items-center justify-between"><h2 class="text-lg font-semibold text-slate-900">基本设置</h2><span class="rounded-full px-3 py-1 text-xs font-medium" :class="selectedStrategy.enabled ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'">{{ selectedStrategy.enabled ? '后端已启用' : '草稿' }}</span></div>
                        <div class="mt-5 grid gap-4 md:grid-cols-3">
                            <label class="text-sm font-medium text-slate-700">名称<input v-model="editStrategyForm.name" class="mt-1 w-full rounded-lg border-slate-300"></label>
                            <label class="text-sm font-medium text-slate-700">算法<select v-model="editStrategyForm.algorithm" class="mt-1 w-full rounded-lg border-slate-300"><option v-for="option in options.algorithms" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
                            <label class="text-sm font-medium text-slate-700">数量<input v-model.number="editStrategyForm.item_limit" type="number" min="1" max="50" class="mt-1 w-full rounded-lg border-slate-300"></label>
                        </div>
                        <button v-if="permissions.manage" class="mt-4 rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white">保存基本设置</button>
                    </form>

                    <form class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" @submit.prevent="updateRules">
                        <h2 class="text-lg font-semibold text-slate-900">过滤规则</h2><p class="mt-1 text-sm text-slate-500">多个包含标签按“命中任一”处理；排除规则始终优先。</p>
                        <div class="mt-5 grid gap-4 md:grid-cols-2">
                            <label class="text-sm font-medium text-slate-700">包含标签<textarea v-model="ruleForm.include_tags_text" rows="2" class="mt-1 w-full rounded-lg border-slate-300" placeholder="Bike, Featured"></textarea></label>
                            <label class="text-sm font-medium text-slate-700">排除标签<textarea v-model="ruleForm.exclude_tags_text" rows="2" class="mt-1 w-full rounded-lg border-slate-300" placeholder="Clearance, Hidden"></textarea></label>
                            <label class="text-sm font-medium text-slate-700">最低价格<input v-model="ruleForm.minimum_price" type="number" min="0" step="0.01" class="mt-1 w-full rounded-lg border-slate-300"></label>
                            <label class="text-sm font-medium text-slate-700">最高价格<input v-model="ruleForm.maximum_price" type="number" min="0" step="0.01" class="mt-1 w-full rounded-lg border-slate-300"></label>
                            <label class="text-sm font-medium text-slate-700">最低库存<input v-model="ruleForm.minimum_inventory" type="number" min="0" class="mt-1 w-full rounded-lg border-slate-300"></label>
                            <label class="flex items-center gap-3 self-end rounded-lg border border-slate-200 px-4 py-3 text-sm text-slate-700"><input v-model="ruleForm.in_stock_only" type="checkbox" class="rounded border-slate-300">仅推荐可售商品</label>
                        </div>
                        <button v-if="permissions.manage" class="mt-4 rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white">保存规则</button>
                    </form>

                    <form class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" @submit.prevent="updateProducts">
                        <h2 class="text-lg font-semibold text-slate-900">手动、置顶与排除</h2><p class="mt-1 text-sm text-slate-500">按住 Command / Ctrl 可多选。同一商品只能属于一种操作。</p>
                        <div class="mt-5 grid gap-4 lg:grid-cols-3">
                            <label v-for="field in productFields" :key="field.key" class="text-sm font-medium text-slate-700">{{ field.label }}
                                <select v-model="productForm[field.key]" multiple size="8" class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option v-for="product in products" :key="product.shopify_gid" :value="product.shopify_gid">{{ product.title }}</option></select>
                            </label>
                        </div>
                        <button v-if="permissions.manage" class="mt-4 rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white">保存商品操作</button>
                    </form>
                </div>
                <div v-else class="rounded-2xl border border-dashed border-slate-300 bg-white p-12 text-center text-slate-400">先创建一个推荐策略。</div>
            </section>

            <section v-else-if="activeTab === 'components'" class="space-y-5">
                <form v-if="permissions.manage" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" @submit.prevent="createComponent">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-end">
                        <label class="flex-1 text-sm font-medium text-slate-700">组件名称<input v-model="createComponentForm.name" required class="mt-1 w-full rounded-lg border-slate-300" placeholder="商品页 · 你可能还喜欢"></label>
                        <label class="flex-1 text-sm font-medium text-slate-700">推荐策略<select v-model="createComponentForm.strategy_uuid" required class="mt-1 w-full rounded-lg border-slate-300"><option value="" disabled>选择策略</option><option v-for="strategy in strategies" :key="strategy.uuid" :value="strategy.uuid">{{ strategy.name }}</option></select></label>
                        <label class="flex-1 text-sm font-medium text-slate-700">展示位置<select v-model="createComponentForm.placement" class="mt-1 w-full rounded-lg border-slate-300"><option v-for="option in options.placements" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
                        <button class="rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50" :disabled="strategies.length === 0">创建组件草稿</button>
                    </div>
                </form>

                <div class="grid gap-5 xl:grid-cols-[300px_1fr]">
                    <aside class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                        <button v-for="component in components" :key="component.uuid" type="button" class="mb-2 w-full rounded-xl border px-4 py-3 text-left" :class="selectedComponentUuid === component.uuid ? 'border-indigo-200 bg-indigo-50' : 'border-transparent hover:bg-slate-50'" @click="selectedComponentUuid = component.uuid">
                            <div class="flex items-center justify-between gap-2"><span class="text-sm font-semibold text-slate-900">{{ component.name }}</span><span class="rounded-full bg-slate-100 px-2 py-1 text-[10px] text-slate-600">{{ statusLabel(component.status) }}</span></div>
                            <p class="mt-1 text-xs text-slate-500">{{ placementLabel(component.placement) }} · {{ component.strategy_name }}</p>
                        </button>
                        <p v-if="components.length === 0" class="p-8 text-center text-sm text-slate-400">尚未创建组件</p>
                    </aside>

                    <div v-if="selectedComponent" class="space-y-5">
                        <form class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" @submit.prevent="updateComponent">
                            <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-lg font-semibold text-slate-900">组件设置</h2><p class="text-sm text-slate-500">保存会退回草稿，需重新预览后启用。</p></div><div class="flex gap-2"><button v-if="permissions.manage && selectedComponent.status !== 'active'" type="button" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white" @click="activateComponent(selectedComponent)">启用后端配置</button><button v-if="permissions.manage && selectedComponent.status === 'active'" type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700" @click="disableComponent(selectedComponent)">停用</button></div></div>
                            <div class="mt-5 grid gap-4 md:grid-cols-2">
                                <label class="text-sm font-medium text-slate-700">名称<input v-model="componentForm.name" class="mt-1 w-full rounded-lg border-slate-300"></label>
                                <label class="text-sm font-medium text-slate-700">策略<select v-model="componentForm.strategy_uuid" class="mt-1 w-full rounded-lg border-slate-300"><option v-for="strategy in strategies" :key="strategy.uuid" :value="strategy.uuid">{{ strategy.name }}</option></select></label>
                                <label class="text-sm font-medium text-slate-700">位置<select v-model="componentForm.placement" class="mt-1 w-full rounded-lg border-slate-300"><option v-for="option in options.placements" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
                                <label class="text-sm font-medium text-slate-700">标题<input v-model="componentForm.heading" class="mt-1 w-full rounded-lg border-slate-300"></label>
                                <label class="text-sm font-medium text-slate-700">按钮文案<input v-model="componentForm.button_label" class="mt-1 w-full rounded-lg border-slate-300"></label>
                            </div>
                            <button v-if="permissions.manage" class="mt-4 rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white">保存组件设置</button>
                        </form>

                        <div class="grid gap-5 2xl:grid-cols-[360px_1fr]">
                            <form class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" @submit.prevent="updateStyle">
                                <h2 class="text-lg font-semibold text-slate-900">样式</h2>
                                <div class="mt-4 grid grid-cols-2 gap-3">
                                    <label class="text-sm text-slate-700">布局<select v-model="styleForm.layout" class="mt-1 w-full rounded-lg border-slate-300"><option value="carousel">横向轮播</option><option value="grid">商品网格</option></select></label>
                                    <label class="text-sm text-slate-700">桌面列数<input v-model.number="styleForm.desktop_columns" type="number" min="1" max="6" class="mt-1 w-full rounded-lg border-slate-300"></label>
                                    <label class="text-sm text-slate-700">移动列数<input v-model.number="styleForm.mobile_columns" type="number" min="1" max="3" class="mt-1 w-full rounded-lg border-slate-300"></label>
                                    <label class="text-sm text-slate-700">圆角<input v-model.number="styleForm.tokens.border_radius" type="number" min="0" max="48" class="mt-1 w-full rounded-lg border-slate-300"></label>
                                    <label class="text-sm text-slate-700">文字颜色<input v-model="styleForm.tokens.text_color" type="color" class="mt-1 h-10 w-full rounded border border-slate-300"></label>
                                    <label class="text-sm text-slate-700">背景颜色<input v-model="styleForm.tokens.background_color" type="color" class="mt-1 h-10 w-full rounded border border-slate-300"></label>
                                </div>
                                <div class="mt-4 grid grid-cols-2 gap-2 text-sm text-slate-700"><label><input v-model="styleForm.show_image" type="checkbox" class="mr-2 rounded">图片</label><label><input v-model="styleForm.show_vendor" type="checkbox" class="mr-2 rounded">品牌</label><label><input v-model="styleForm.show_price" type="checkbox" class="mr-2 rounded">价格</label><label><input v-model="styleForm.show_add_to_cart" type="checkbox" class="mr-2 rounded">加购按钮</label></div>
                                <button v-if="permissions.manage" class="mt-5 w-full rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white">保存样式</button>
                            </form>

                            <div class="rounded-2xl border border-slate-200 bg-slate-100 p-5 shadow-sm">
                                <div class="mb-4 flex items-center justify-between"><div><h2 class="font-semibold text-slate-900">实时预览</h2><p class="text-xs text-slate-500">示例上下文只用于离线预览</p></div><div class="flex rounded-lg bg-white p-1 text-xs"><button type="button" class="rounded-md px-3 py-1.5" :class="previewMode === 'desktop' ? 'bg-slate-950 text-white' : 'text-slate-500'" @click="previewMode = 'desktop'">桌面</button><button type="button" class="rounded-md px-3 py-1.5" :class="previewMode === 'mobile' ? 'bg-slate-950 text-white' : 'text-slate-500'" @click="previewMode = 'mobile'">移动</button></div></div>
                                <div class="mx-auto min-h-72 overflow-hidden border border-slate-200 bg-white p-5 shadow-sm transition-all" :class="previewMode === 'mobile' ? 'max-w-[390px]' : 'max-w-full'" :style="{ backgroundColor: String(styleForm.tokens.background_color), color: String(styleForm.tokens.text_color), borderRadius: `${styleForm.tokens.border_radius}px` }">
                                    <h3 class="mb-4 text-lg font-semibold">{{ componentForm.heading || '你可能还喜欢' }}</h3>
                                    <p v-if="previewLoading" class="py-16 text-center text-sm text-slate-400">正在生成预览…</p><p v-else-if="previewError" class="rounded-lg bg-rose-50 p-4 text-sm text-rose-700">{{ previewError }}</p><p v-else-if="previewItems.length === 0" class="py-16 text-center text-sm text-slate-400">当前示例上下文没有符合规则的商品。</p>
                                    <div v-else class="grid" :style="{ gridTemplateColumns: `repeat(${previewColumns}, minmax(0, 1fr))`, gap: `${styleForm.tokens.gap}px` }">
                                        <article v-for="product in previewItems" :key="product.shopify_product_id" class="min-w-0 rounded-xl border border-slate-200 bg-white p-3">
                                            <div v-if="styleForm.show_image" class="aspect-square overflow-hidden rounded-lg bg-slate-100"><img v-if="product.storefront.image.url" :src="product.storefront.image.url" :alt="product.storefront.image.alt ?? product.title" class="size-full object-cover"><div v-else class="flex size-full items-center justify-center text-xs text-slate-400">暂无图片</div></div>
                                            <p v-if="styleForm.show_vendor && product.vendor" class="mt-3 text-[10px] uppercase tracking-wider text-slate-400">{{ product.vendor }}</p><h4 class="mt-1 truncate text-sm font-semibold">{{ product.title }}</h4><p v-if="styleForm.show_price" class="mt-1 text-sm">{{ money(product.price.minimum, product.price.currency) }}</p><button v-if="styleForm.show_add_to_cart" type="button" class="mt-3 w-full rounded-lg px-2 py-2 text-xs font-semibold" :style="{ backgroundColor: String(styleForm.tokens.button_color), color: String(styleForm.tokens.button_text_color) }">{{ componentForm.button_label || '加入购物车' }}</button>
                                        </article>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section v-else-if="activeTab === 'smart-cart'" class="grid gap-5 lg:grid-cols-[1fr_.8fr]">
                <form class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" @submit.prevent="saveSmartCartDraft">
                    <div class="flex items-center justify-between"><div><h2 class="text-lg font-semibold text-slate-900">Smart Cart 基础版</h2><p class="mt-1 text-sm text-slate-500">这里只保存草稿，不提供启用操作。</p></div><span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">默认关闭</span></div>
                    <div class="mt-5 grid gap-4 md:grid-cols-2"><label class="text-sm font-medium text-slate-700">推荐策略<select v-model="smartCartForm.strategy_uuid" class="mt-1 w-full rounded-lg border-slate-300"><option value="">暂不选择</option><option v-for="strategy in strategies" :key="strategy.uuid" :value="strategy.uuid">{{ strategy.name }}</option></select></label><label class="text-sm font-medium text-slate-700">标题<input v-model="smartCartForm.heading" class="mt-1 w-full rounded-lg border-slate-300" maxlength="120"></label></div>
                    <button v-if="permissions.manageSmartCart" class="mt-5 rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white">保存关闭状态草稿</button><p v-else class="mt-5 text-sm text-slate-500">当前账号没有 Smart Cart 管理权限。</p>
                </form>
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-6"><h2 class="font-semibold text-emerald-950">固定安全条件</h2><ul class="mt-4 space-y-3 text-sm leading-6 text-emerald-900"><li>✓ 必须先通过主题兼容性检查</li><li>✓ 必须完成桌面和移动预览</li><li>✓ 必须由人工显式启用</li><li>✓ 一键恢复 Shopify 默认购物车</li></ul><dl class="mt-5 grid grid-cols-2 gap-3 text-sm"><div class="rounded-lg bg-white p-3"><dt class="text-slate-500">兼容状态</dt><dd class="mt-1 font-semibold">{{ smartCart?.compatibility_status ?? 'unchecked' }}</dd></div><div class="rounded-lg bg-white p-3"><dt class="text-slate-500">回退方式</dt><dd class="mt-1 font-semibold">Shopify 默认购物车</dd></div></dl></div>
            </section>

            <section v-else class="space-y-5">
                <div v-if="!permissions.viewAnalytics" class="rounded-2xl border border-slate-200 bg-white p-12 text-center text-slate-500">当前账号没有个性化推荐分析权限。</div>
                <template v-else><div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6"><div v-for="card in analyticsCards" :key="card[0]" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-medium text-slate-500">{{ card[0] }}</p><p class="mt-2 text-2xl font-semibold text-slate-950">{{ card[1] }}</p></div></div><div class="rounded-2xl border border-indigo-200 bg-indigo-50 p-6 text-sm leading-6 text-indigo-900">事件采集和 7 天最后推荐点击归因将在后续阶段接入。当前保持 0 值，不使用模拟分析数据。</div></template>
            </section>
        </div>
    </AppLayout>
</template>
