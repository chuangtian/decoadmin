<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';

type Algorithm = 'manual' | 'best_seller' | 'new_arrivals' | 'frequently_bought_together' | 'recently_viewed' | 'similar_products';
type Placement = 'homepage' | 'product_page' | 'cart_page' | 'smart_cart' | 'checkout';
type StrategyStatus = 'draft' | 'enabled' | 'disabled' | 'configuration_error' | 'archived';
type TopTab = 'overview' | 'strategies' | 'analytics';
type EditorStep = 'basic' | 'rules' | 'products' | 'discount' | 'usage' | 'preview';
type SaveState = 'idle' | 'saving' | 'saved' | 'failed';

interface Usage { component_uuid: string; name: string; placement: Placement; status: 'configured_not_enabled' | 'live' | 'disabled' | 'configuration_error' }
interface StrategyRow {
    uuid: string; name: string; technical_id: string; status: StrategyStatus; algorithm: Algorithm; item_limit: number;
    used_in: Usage[]; created_at: string | null; updated_at: string | null;
    published_version: { uuid: string; version_number: number; published_at: string | null } | null;
    has_draft: boolean; recycle_until: string | null;
}
interface PlacementDraft {
    placement: Placement; enabled: boolean; component_uuid: string | null; name: string; heading: string; button_label: string;
    style: { layout: 'carousel' | 'grid'; desktop_columns: number; mobile_columns: number; show_image: boolean; show_vendor: boolean; show_price: boolean; show_compare_at_price: boolean; show_add_to_cart: boolean; tokens: Record<string, never> };
}
interface StrategyDraft {
    uuid: string; version_number: number; status: 'draft' | 'published' | 'superseded'; name: string; algorithm: Algorithm; item_limit: number; lock_version: number; updated_at: string | null;
    configuration: {
        rules: { include_tags: string[]; exclude_tags: string[]; include_collection_ids: string[]; exclude_collection_ids: string[]; exclude_vendors: string[]; minimum_price: string | null; maximum_price: string | null; minimum_inventory: number | null; in_stock_only: boolean; exclude_cart_products: boolean; exclude_purchased_products: boolean };
        products: { manual: string[]; pinned: string[]; excluded: string[] };
        discount: { enabled: boolean; reference: string | null };
        placements: PlacementDraft[];
        checkout: { maximum_recommendations: number | null; collection_id: string | null };
    };
}
interface VersionRow { uuid: string; version_number: number; status: string; name: string; created_at: string | null; published_at: string | null; is_current: boolean }
interface ProductOption {
    shopify_product_id: string; shopify_gid: string; title: string; handle: string; image_url: string | null; price: string | null; currency: string; tags: string[]; collection_ids: string[];
    variants: Array<{ shopify_variant_id: string; shopify_gid: string; title: string; sku: string | null; price: string; available_for_sale: boolean }>;
}
interface CheckoutTrustItem { key: string; icon: string; title: string; description: string; position: number; enabled: boolean }
interface ComponentOption { uuid: string; strategy_uuid: string; strategy_name: string; name: string; placement: Placement; status: string; heading: string | null; button_label: string | null }

const props = defineProps<{
    organization: { id: number; name: string };
    store: { id: number; name: string; shopify_domain: string; currency: string };
    strategyRows: StrategyRow[]; recycledStrategies: StrategyRow[];
    strategies: Array<{ uuid: string; name: string; algorithm: Algorithm; enabled: boolean }>;
    components: ComponentOption[]; products: ProductOption[];
    collections: Array<{ shopify_collection_id: string; title: string; handle: string; sort_order: string | null; product_count: number }>;
    checkout: { uuid: string | null; enabled: boolean; component_uuid: string | null; trust_items: CheckoutTrustItem[]; shopify_collection_id: string | null; settings: { maximum_recommendations?: number | null }; icon_options: Array<{ value: string; label: string }> };
    smartCart: { uuid: string; strategy_uuid: string | null; enabled: boolean; compatibility_status: string; compatibility_details: { checks?: Array<{ key: string; label: string; passed: boolean; details: string | null }> }; compatibility_checked_at: string | null; theme_id: string | null; theme_name: string | null; preview_confirmed_at: string | null; fallback_mode: string; settings: Record<string, unknown> } | null;
    globalSettings: { default_locale: 'zh-CN' | 'en'; copy: { recommendation_heading: string; add_button: string; checkout_heading: string }; attribution: { model: string; window_days: number; click_only: boolean; refund_cancel_reversal: boolean } };
    options: { algorithms: Array<{ value: Algorithm; label: string }>; placements: Array<{ value: Placement; label: string }> };
    permissions: { manage: boolean; manageSmartCart: boolean; viewAnalytics: boolean };
    analytics: {
        status: string; period: { days: number; from: string; to: string; timezone: string }; currency: string; impressions: number; clicks: number; add_to_carts: number; orders: number; attributed_revenue: string; aov: string; click_through_rate: number; add_to_cart_rate: number; reversed_orders: number; excluded_currency_orders: number;
        attribution: { model: string; window_days: number; click_only: boolean; refund_cancel_reversal: boolean };
        daily: Array<{ date: string; impressions: number; clicks: number; add_to_carts: number; orders: number; attributed_revenue: string }>;
        placements: Array<{ placement: string; impressions: number; clicks: number; add_to_carts: number; orders: number; attributed_revenue: string; click_through_rate: number }>;
        dimensions: Array<{ strategy_uuid: string | null; strategy_name: string; strategy_version_uuid: string | null; strategy_version: number | null; component_uuid: string | null; component_name: string; placement: string; impressions: number; clicks: number; add_to_carts: number; orders: number; attributed_revenue: string; click_through_rate: number }>;
    };
}>();

const baseUrl = `/organizations/${props.organization.id}/stores/${props.store.id}/personalization`;
const activeTab = ref<TopTab>('overview');
const tabs: Array<{ value: TopTab; label: string; hint: string }> = [
    { value: 'overview', label: '概览', hint: '上线状态与全局设置' },
    { value: 'strategies', label: '策略', hint: '制定、发布与恢复' },
    { value: 'analytics', label: '分析', hint: '按版本和页面拆分' },
];
const strategyRows = ref<StrategyRow[]>(props.strategyRows.map(row => ({ ...row })));
const recycledRows = ref<StrategyRow[]>(props.recycledStrategies.map(row => ({ ...row })));
watch(() => props.strategyRows, value => { strategyRows.value = value.map(row => ({ ...row })); }, { deep: true });
watch(() => props.recycledStrategies, value => { recycledRows.value = value.map(row => ({ ...row })); }, { deep: true });

const algorithmLabel = (value: Algorithm) => props.options.algorithms.find(option => option.value === value)?.label ?? value;
const placementLabel = (value: string) => props.options.placements.find(option => option.value === value)?.label ?? value;
const statusLabel = (value: StrategyStatus) => ({ draft: '草稿', enabled: '已启用', disabled: '已停用', configuration_error: '配置异常', archived: '回收站' }[value]);
const statusClass = (value: StrategyStatus) => ({ draft: 'bg-slate-100 text-slate-700', enabled: 'bg-emerald-100 text-emerald-800', disabled: 'bg-amber-100 text-amber-800', configuration_error: 'bg-rose-100 text-rose-800', archived: 'bg-violet-100 text-violet-800' }[value]);
const usageLabel = (value: Usage['status']) => ({ configured_not_enabled: '已配置未启用', live: '已上线', disabled: '已停用', configuration_error: '配置异常' }[value]);
const formatDate = (value: string | null) => value ? new Intl.DateTimeFormat('zh-CN', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—';
const money = (value: string | null, currency = props.store.currency) => value === null ? '—' : new Intl.NumberFormat('zh-CN', { style: 'currency', currency }).format(Number(value));
const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
const requestId = () => globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-0000-4000-8000-${Math.random().toString(16).slice(2).padEnd(12, '0').slice(0, 12)}`;

class ApiError extends Error { constructor(public code: string, message: string, public status: number) { super(message); } }
async function requestJson<T>(url: string, options: RequestInit = {}): Promise<T> {
    const response = await fetch(url, { ...options, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), ...(options.headers ?? {}) } });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        const validation = payload?.errors ? Object.values(payload.errors).flat().join('；') : '';
        throw new ApiError(payload?.error?.code ?? 'REQUEST_FAILED', payload?.error?.message ?? validation ?? '操作失败，请重试。', response.status);
    }
    return payload.data as T;
}

const search = ref('');
const showRecycleBin = ref(false);
const filteredStrategies = computed(() => {
    const keyword = search.value.trim().toLocaleLowerCase();
    return strategyRows.value.filter(row => !keyword || [row.name, row.technical_id, algorithmLabel(row.algorithm), ...row.used_in.map(item => item.name)].some(value => value.toLocaleLowerCase().includes(keyword)));
});

const editorOpen = ref(false);
const editorStep = ref<EditorStep>('basic');
const editorStrategy = ref<StrategyRow | null>(null);
const editorDraft = ref<StrategyDraft | null>(null);
const versions = ref<VersionRow[]>([]);
const editorLoading = ref(false);
const editorError = ref('');
const saveState = ref<SaveState>('idle');
const savedAt = ref('');
const closePrompt = ref(false);
const replacementPrompt = ref(false);
const replacementMessage = ref('');
let autosaveTimer: ReturnType<typeof setTimeout> | null = null;
let ignoreDraftWatch = false;
let pendingSave: Promise<void> | null = null;
const editorSteps: Array<{ value: EditorStep; label: string }> = [
    { value: 'basic', label: '基本信息' }, { value: 'rules', label: '推荐规则' }, { value: 'products', label: '置顶与排除' },
    { value: 'discount', label: '优惠' }, { value: 'usage', label: '使用场景' }, { value: 'preview', label: '预览与启用' },
];

function normalizeEditorPayload(payload: { strategy: StrategyRow; draft: StrategyDraft; versions: VersionRow[] }) {
    ignoreDraftWatch = true;
    editorStrategy.value = payload.strategy;
    editorDraft.value = structuredClone(payload.draft);
    versions.value = payload.versions;
    saveState.value = 'saved';
    savedAt.value = payload.draft.updated_at ?? '';
    queueMicrotask(() => { ignoreDraftWatch = false; });
}
async function createStrategy() {
    editorOpen.value = true; editorLoading.value = true; editorError.value = ''; editorStep.value = 'basic';
    try {
        const payload = await requestJson<{ strategy: StrategyRow; draft: StrategyDraft; versions: VersionRow[] }>(`${baseUrl}/strategy-workflow/drafts`, { method: 'POST', body: JSON.stringify({ idempotency_key: requestId() }) });
        normalizeEditorPayload(payload); upsertStrategy(payload.strategy);
    } catch (error) { editorError.value = error instanceof Error ? error.message : '无法创建策略草稿。'; }
    finally { editorLoading.value = false; }
}
async function openEditor(strategy: StrategyRow, step: EditorStep = 'basic') {
    editorOpen.value = true; editorLoading.value = true; editorError.value = ''; editorStep.value = step;
    try { normalizeEditorPayload(await requestJson(`${baseUrl}/strategy-workflow/${strategy.uuid}`)); }
    catch (error) { editorError.value = error instanceof Error ? error.message : '无法读取策略草稿。'; }
    finally { editorLoading.value = false; }
}
function scheduleAutosave() {
    if (!editorOpen.value || !editorDraft.value || ignoreDraftWatch) return;
    saveState.value = 'idle';
    if (autosaveTimer) clearTimeout(autosaveTimer);
    autosaveTimer = setTimeout(() => { void saveDraft(); }, 800);
}
watch(editorDraft, scheduleAutosave, { deep: true });
async function saveDraft() {
    if (!editorStrategy.value || !editorDraft.value) return;
    if (pendingSave) return pendingSave;
    if (autosaveTimer) { clearTimeout(autosaveTimer); autosaveTimer = null; }
    saveState.value = 'saving';
    const draftSnapshot = structuredClone(editorDraft.value);
    pendingSave = (async () => {
        try {
            const payload = await requestJson<{ draft: StrategyDraft; saved_at: string }>(`${baseUrl}/strategy-workflow/${editorStrategy.value!.uuid}/draft`, { method: 'PATCH', body: JSON.stringify({ idempotency_key: requestId(), lock_version: draftSnapshot.lock_version, draft: draftSnapshot }) });
            ignoreDraftWatch = true; editorDraft.value = structuredClone(payload.draft); saveState.value = 'saved'; savedAt.value = payload.saved_at;
            const row = strategyRows.value.find(item => item.uuid === editorStrategy.value?.uuid);
            if (row) { row.name = payload.draft.name; row.algorithm = payload.draft.algorithm; row.updated_at = payload.saved_at; row.has_draft = true; }
            queueMicrotask(() => { ignoreDraftWatch = false; });
        } catch (error) { saveState.value = 'failed'; editorError.value = error instanceof Error ? error.message : '自动保存失败。'; }
        finally { pendingSave = null; }
    })();
    return pendingSave;
}
async function requestClose() {
    if (saveState.value === 'failed') { closePrompt.value = true; return; }
    if (saveState.value === 'saving' || saveState.value === 'idle') {
        await saveDraft();
        if ((saveState.value as SaveState) === 'failed') { closePrompt.value = true; return; }
    }
    closeEditor();
}
function closeEditor() {
    if (autosaveTimer) clearTimeout(autosaveTimer);
    autosaveTimer = null; editorOpen.value = false; editorDraft.value = null; editorStrategy.value = null; closePrompt.value = false; replacementPrompt.value = false; editorError.value = '';
}
function onKeydown(event: KeyboardEvent) { if (event.key === 'Escape' && editorOpen.value) { event.preventDefault(); requestClose(); } }
onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => { window.removeEventListener('keydown', onKeydown); if (autosaveTimer) clearTimeout(autosaveTimer); });

function splitValues(value: string) { return value.split(/[\n,]/).map(item => item.trim()).filter(Boolean); }
function joined(values: string[]) { return values.join(', '); }
function updateStringList(target: string[], value: string) { target.splice(0, target.length, ...splitValues(value)); }
function placementDraft(type: Placement): PlacementDraft | null { return editorDraft.value?.configuration.placements.find(item => item.placement === type) ?? null; }
function togglePlacement(type: Placement, enabled: boolean) {
    if (!editorDraft.value) return;
    let placement = placementDraft(type);
    if (!placement) {
        placement = { placement: type, enabled, component_uuid: null, name: `${placementLabel(type)}推荐`, heading: props.globalSettings.copy.recommendation_heading, button_label: props.globalSettings.copy.add_button, style: { layout: 'carousel', desktop_columns: 4, mobile_columns: 2, show_image: true, show_vendor: false, show_price: true, show_compare_at_price: true, show_add_to_cart: true, tokens: {} } };
        editorDraft.value.configuration.placements.push(placement);
    } else if (!enabled) {
        editorDraft.value.configuration.placements = editorDraft.value.configuration.placements.filter(item => item.placement !== type);
    } else placement.enabled = true;
}
function removePlacement(type: Placement) {
    if (!editorDraft.value) return;
    editorDraft.value.configuration.placements = editorDraft.value.configuration.placements.filter(item => item.placement !== type);
}
const selectedUsagePlacement = ref<Placement>('homepage');
const selectedPlacementDraft = computed(() => placementDraft(selectedUsagePlacement.value));

const previewContext = reactive({ seed_product_id: '', cart_product_ids: [] as string[], purchased_product_ids: [] as string[] });
const previewLoading = ref(false);
const previewError = ref('');
const previewResult = ref<null | { items: Array<{ shopify_product_id: string; title: string; reason_code: string; price: { minimum: string | null; currency: string }; storefront: { image: { url: string | null } } }>; skipped: Array<{ shopify_product_id: string; title: string; reason: string }>; checkout_sequence: { maximum_recommendations: number | null; items: Array<{ shopify_product_id: string; title: string }> } }>(null);
const skipReason = (reason: string) => ({ already_in_cart: '已在购物车', already_purchased: '已购买', excluded_by_strategy: '被策略排除', out_of_stock_or_market_unavailable: '无库存或当前市场不可售' }[reason] ?? reason);
async function loadPreview() {
    if (!editorStrategy.value) return;
    await saveDraft(); if (saveState.value === 'failed') return;
    previewLoading.value = true; previewError.value = '';
    try { previewResult.value = await requestJson(`${baseUrl}/strategy-workflow/${editorStrategy.value.uuid}/preview`, { method: 'POST', body: JSON.stringify(previewContext) }); }
    catch (error) { previewResult.value = null; previewError.value = error instanceof Error ? error.message : '无法生成预览。'; }
    finally { previewLoading.value = false; }
}
async function publishStrategy(confirmReplacements = false) {
    if (!editorStrategy.value || !editorDraft.value) return;
    await saveDraft(); if (saveState.value === 'failed') return;
    editorError.value = '';
    try {
        const payload = await requestJson<{ strategy: StrategyRow; published_version: StrategyDraft }>(`${baseUrl}/strategy-workflow/${editorStrategy.value.uuid}/publish`, { method: 'POST', body: JSON.stringify({ idempotency_key: requestId(), lock_version: editorDraft.value.lock_version, confirm_replacements: confirmReplacements }) });
        upsertStrategy(payload.strategy); replacementPrompt.value = false;
        router.reload({ only: ['strategyRows', 'components', 'checkout', 'smartCart'] });
        await openEditor(payload.strategy, 'preview');
    } catch (error) {
        if (error instanceof ApiError && error.code === 'PLACEMENT_REPLACEMENT_CONFIRMATION_REQUIRED') { replacementMessage.value = error.message; editorError.value = error.message; replacementPrompt.value = true; }
        else editorError.value = error instanceof Error ? error.message : '发布失败。';
    }
}
async function restoreVersion(version: VersionRow) {
    if (!editorStrategy.value || !confirm(`确认将版本 ${version.version_number} 恢复为新的线上版本？历史版本不会被覆盖。`)) return;
    try {
        const payload = await requestJson<{ strategy: StrategyRow; published_version: StrategyDraft }>(`${baseUrl}/strategy-workflow/${editorStrategy.value.uuid}/versions/${version.uuid}/restore`, { method: 'POST', body: JSON.stringify({ idempotency_key: requestId(), confirm_replacements: true }) });
        upsertStrategy(payload.strategy); router.reload({ only: ['strategyRows', 'components'] }); await openEditor(payload.strategy, 'preview');
    } catch (error) { editorError.value = error instanceof Error ? error.message : '版本恢复失败。'; }
}
function upsertStrategy(row: StrategyRow) { const index = strategyRows.value.findIndex(item => item.uuid === row.uuid); if (index < 0) strategyRows.value.unshift(row); else strategyRows.value.splice(index, 1, row); }
async function duplicateStrategy(strategy: StrategyRow) {
    try {
        const payload = await requestJson<{ strategy: StrategyRow; draft: StrategyDraft; versions: VersionRow[] }>(`${baseUrl}/strategy-workflow/${strategy.uuid}/duplicate`, { method: 'POST', body: JSON.stringify({ idempotency_key: requestId() }) });
        upsertStrategy(payload.strategy); editorOpen.value = true; editorStep.value = 'basic'; normalizeEditorPayload(payload);
    } catch (error) { alert(error instanceof Error ? error.message : '复制失败。'); }
}
async function disableStrategy(strategy: StrategyRow) {
    if (!confirm(`确认停用“${strategy.name}”及其线上组件？`)) return;
    try { upsertStrategy(await requestJson(`${baseUrl}/strategy-workflow/${strategy.uuid}/disable`, { method: 'POST', body: '{}' })); }
    catch (error) { alert(error instanceof Error ? error.message : '停用失败。'); }
}
async function recycleStrategy(strategy: StrategyRow) {
    if (strategy.used_in.length) {
        alert('该策略仍有关联页面或组件，请先在“使用场景”中解除关联并发布。');
        await openEditor(strategy, 'usage');
        return;
    }
    if (!confirm(`将“${strategy.name}”移入回收站？30 天内可以恢复。`)) return;
    try {
        await requestJson(`${baseUrl}/strategy-workflow/${strategy.uuid}`, { method: 'DELETE' });
        strategyRows.value = strategyRows.value.filter(item => item.uuid !== strategy.uuid);
        recycledRows.value.unshift({ ...strategy, status: 'archived', recycle_until: new Date(Date.now() + 30 * 86400000).toISOString() });
    } catch (error) { alert(error instanceof Error ? error.message : '无法删除策略。'); }
}
async function restoreStrategy(strategy: StrategyRow) {
    try {
        const restored = await requestJson<StrategyRow>(`${baseUrl}/strategy-workflow/recycle-bin/${strategy.uuid}/restore`, { method: 'POST', body: '{}' });
        recycledRows.value = recycledRows.value.filter(item => item.uuid !== strategy.uuid); upsertStrategy(restored);
    } catch (error) { alert(error instanceof Error ? error.message : '恢复失败。'); }
}

const showGlobalSettings = ref(false);
const globalForm = reactive(structuredClone(props.globalSettings));
const globalSaveState = ref<SaveState>('idle');
const globalError = ref('');
async function saveGlobalSettings() {
    globalSaveState.value = 'saving'; globalError.value = '';
    try { await requestJson(`${baseUrl}/global-settings`, { method: 'PUT', body: JSON.stringify({ default_locale: globalForm.default_locale, copy: globalForm.copy }) }); globalSaveState.value = 'saved'; }
    catch (error) { globalSaveState.value = 'failed'; globalError.value = error instanceof Error ? error.message : '保存失败。'; }
}
const checkoutComponents = computed(() => props.components.filter(component => component.placement === 'checkout'));
const checkoutForm = useForm({ enabled: props.checkout.enabled, component_uuid: props.checkout.component_uuid ?? '', shopify_collection_id: props.checkout.shopify_collection_id ?? '', maximum_recommendations: props.checkout.settings.maximum_recommendations ?? null as number | null, trust_items: props.checkout.trust_items.map(item => ({ ...item })) });
function addTrustItem() { if (checkoutForm.trust_items.length < 6) checkoutForm.trust_items.push({ key: `trust_${Date.now()}`, icon: 'check-circle', title: '', description: '', position: checkoutForm.trust_items.length + 1, enabled: true }); }
function saveCheckout() {
    checkoutForm.transform(data => ({ ...data, component_uuid: data.component_uuid || null, shopify_collection_id: data.shopify_collection_id || null, maximum_recommendations: data.maximum_recommendations || null, trust_items: data.trust_items.map((item, index) => ({ ...item, position: index + 1 })) })).put(`${baseUrl}/checkout`, { preserveScroll: true });
}
const smartCartForm = useForm({ strategy_uuid: props.smartCart?.strategy_uuid ?? '', heading: String(props.smartCart?.settings?.heading ?? '购物车推荐') });
const smartChecks = useForm({ theme_id: props.smartCart?.theme_id ?? '', theme_name: props.smartCart?.theme_name ?? '', unpublished_copy: false, app_embed_loaded: false, browser_dialog: false, cart_link: false, cart_routes: false, cart_behaviour_verified: false });
function saveSmartCartDraft() { smartCartForm.put(`${baseUrl}/smart-cart`, { preserveScroll: true }); }
function saveSmartCompatibility() {
    smartChecks.transform(data => ({ theme_id: data.theme_id, theme_name: data.theme_name, checks: [
        ['unpublished_copy', '使用未发布的测试主题副本'], ['app_embed_loaded', 'App Embed 已加载'], ['browser_dialog', '浏览器支持安全购物车抽屉'], ['cart_link', '可识别购物车入口'], ['cart_routes', '购物车接口可用'], ['cart_behaviour_verified', '购物车行为已验证'],
    ].map(([key, label]) => ({ key, label, passed: Boolean(data[key as keyof typeof data]), details: null })) })).post(`${baseUrl}/smart-cart/compatibility`, { preserveScroll: true });
}
const restoreShopifyCart = () => router.post(`${baseUrl}/smart-cart/restore`, {}, { preserveScroll: true });
const confirmSmartCartPreview = () => router.post(`${baseUrl}/smart-cart/preview-confirmation`, {}, { preserveScroll: true });
const activateSmartCart = () => router.post(`${baseUrl}/smart-cart/activate`, {}, { preserveScroll: true });

const setupChecks = computed(() => [
    { label: '至少一个策略已发布', passed: strategyRows.value.some(row => row.status === 'enabled') },
    { label: '至少一个页面或组件已上线', passed: strategyRows.value.some(row => row.used_in.some(usage => usage.status === 'live')) },
    { label: 'Checkout 配置已启用', passed: props.checkout.enabled },
    { label: 'Smart Cart 已通过人工安全流程', passed: Boolean(props.smartCart?.enabled) },
]);
const configurationAlerts = computed(() => strategyRows.value.filter(row => row.status === 'configuration_error'));
const analyticsStrategy = ref(''); const analyticsVersion = ref(''); const analyticsPlacement = ref('');
const analyticsRows = computed(() => props.analytics.dimensions.filter(row => (!analyticsStrategy.value || row.strategy_uuid === analyticsStrategy.value) && (!analyticsVersion.value || row.strategy_version_uuid === analyticsVersion.value) && (!analyticsPlacement.value || row.placement === analyticsPlacement.value)));
const analyticsVersions = computed(() => props.analytics.dimensions.filter(row => !analyticsStrategy.value || row.strategy_uuid === analyticsStrategy.value).filter((row, index, rows) => row.strategy_version_uuid && rows.findIndex(item => item.strategy_version_uuid === row.strategy_version_uuid) === index));
const analyticsCards = computed(() => [['曝光', props.analytics.impressions.toLocaleString()], ['点击', props.analytics.clicks.toLocaleString()], ['加购', props.analytics.add_to_carts.toLocaleString()], ['订单', props.analytics.orders.toLocaleString()], ['归因收入', money(props.analytics.attributed_revenue, props.analytics.currency)], ['AOV', money(props.analytics.aov, props.analytics.currency)]]);
</script>

<template>
    <Head title="Deco 个性化推荐" />
    <AppLayout>
        <div class="mx-auto max-w-[1480px] space-y-6 p-4 sm:p-6 lg:p-8">
            <header class="overflow-hidden rounded-3xl bg-slate-950 px-6 py-7 text-white shadow-xl sm:px-8">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                    <div><p class="text-xs font-semibold uppercase tracking-[.22em] text-indigo-300">Deco Personalization</p><h1 class="mt-2 text-3xl font-semibold">个性化推荐</h1><p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">使用 Commerce Hub 的真实商品、库存和订单数据制定确定性推荐策略。草稿自动保存，只有明确发布后才影响线上顾客。</p></div>
                    <div class="rounded-2xl bg-white/10 px-4 py-3 text-sm"><p class="font-semibold">{{ store.name }}</p><p class="mt-1 text-xs text-slate-300">{{ store.shopify_domain }}</p></div>
                </div>
            </header>
            <nav class="grid grid-cols-3 gap-2 rounded-2xl border border-slate-200 bg-white p-2 shadow-sm" aria-label="个性化推荐主导航">
                <button v-for="tab in tabs" :key="tab.value" type="button" class="rounded-xl px-3 py-3 text-left transition" :class="activeTab === tab.value ? 'bg-slate-950 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'" @click="activeTab = tab.value"><span class="block text-sm font-semibold">{{ tab.label }}</span><span class="mt-0.5 hidden text-xs opacity-70 sm:block">{{ tab.hint }}</span></button>
            </nav>

            <section v-if="activeTab === 'overview'" class="space-y-6">
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-medium text-slate-500">策略</p><p class="mt-2 text-3xl font-semibold">{{ strategyRows.length }}</p><p class="mt-2 text-xs text-slate-500">{{ strategyRows.filter(row => row.status === 'enabled').length }} 个已启用</p></div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-medium text-slate-500">线上场景</p><p class="mt-2 text-3xl font-semibold">{{ strategyRows.reduce((total, row) => total + row.used_in.filter(item => item.status === 'live').length, 0) }}</p><p class="mt-2 text-xs text-slate-500">同一页面组件仅允许一个有效策略</p></div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-medium text-slate-500">Checkout</p><p class="mt-2 text-xl font-semibold">{{ checkout.enabled ? '已启用' : '默认关闭' }}</p><p class="mt-2 text-xs text-slate-500">信任信息与集合递进推荐</p></div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs font-medium text-slate-500">Smart Cart</p><p class="mt-2 text-xl font-semibold">{{ smartCart?.enabled ? '已启用' : '默认关闭' }}</p><p class="mt-2 text-xs text-slate-500">{{ smartCart?.compatibility_status ?? 'unchecked' }}</p></div>
                </div>
                <div class="grid gap-6 xl:grid-cols-[1.1fr_.9fr]">
                    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><div class="flex items-start justify-between gap-4"><div><h2 class="text-lg font-semibold">上线检查</h2><p class="mt-1 text-sm text-slate-500">发布前确认策略、场景和店面能力。</p></div><span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700">{{ setupChecks.filter(item => item.passed).length }}/{{ setupChecks.length }}</span></div><div class="mt-5 space-y-3"><div v-for="item in setupChecks" :key="item.label" class="flex items-center gap-3 rounded-xl border p-3" :class="item.passed ? 'border-emerald-100 bg-emerald-50' : 'border-slate-200 bg-slate-50'"><span class="flex size-7 items-center justify-center rounded-full text-xs font-bold" :class="item.passed ? 'bg-emerald-600 text-white' : 'bg-white text-slate-400'">{{ item.passed ? '✓' : '·' }}</span><span class="text-sm font-medium text-slate-700">{{ item.label }}</span></div></div></div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><div class="flex items-start justify-between gap-4"><div><h2 class="text-lg font-semibold">异常提醒</h2><p class="mt-1 text-sm text-slate-500">仅展示当前店铺真实状态。</p></div><span class="rounded-full px-3 py-1 text-xs font-semibold" :class="configurationAlerts.length ? 'bg-rose-100 text-rose-800' : 'bg-emerald-100 text-emerald-800'">{{ configurationAlerts.length ? `${configurationAlerts.length} 项` : '正常' }}</span></div><div v-if="configurationAlerts.length" class="mt-5 space-y-2"><button v-for="strategy in configurationAlerts" :key="strategy.uuid" type="button" class="block w-full rounded-xl border border-rose-200 bg-rose-50 p-3 text-left text-sm text-rose-900" @click="openEditor(strategy, 'usage')">{{ strategy.name }}：页面或组件绑定配置异常</button></div><p v-else class="mt-8 text-center text-sm text-slate-500">没有发现配置异常。</p></div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"><div><h2 class="text-lg font-semibold">全局设置</h2><p class="mt-1 text-sm text-slate-500">默认文案、Checkout 信任信息、Smart Cart 和归因口径集中管理。</p></div><button type="button" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white" @click="showGlobalSettings = !showGlobalSettings">{{ showGlobalSettings ? '收起设置' : '打开全局设置' }}</button></div></div>

                <div v-if="showGlobalSettings" class="space-y-6">
                    <form class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" @submit.prevent="saveGlobalSettings"><h2 class="text-lg font-semibold">默认语言与文案</h2><div class="mt-5 grid gap-4 md:grid-cols-2"><label class="text-sm font-medium">默认语言<select v-model="globalForm.default_locale" class="mt-1 w-full rounded-xl border-slate-300"><option value="zh-CN">简体中文</option><option value="en">English</option></select></label><label class="text-sm font-medium">推荐标题<input v-model="globalForm.copy.recommendation_heading" class="mt-1 w-full rounded-xl border-slate-300" maxlength="120"></label><label class="text-sm font-medium">加购按钮<input v-model="globalForm.copy.add_button" class="mt-1 w-full rounded-xl border-slate-300" maxlength="60"></label><label class="text-sm font-medium">Checkout 标题<input v-model="globalForm.copy.checkout_heading" class="mt-1 w-full rounded-xl border-slate-300" maxlength="120"></label></div><div class="mt-5 flex items-center gap-3"><button v-if="permissions.manage" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white" :disabled="globalSaveState === 'saving'">{{ globalSaveState === 'saving' ? '保存中…' : '保存默认设置' }}</button><span class="text-sm" :class="globalSaveState === 'failed' ? 'text-rose-700' : 'text-emerald-700'">{{ globalError || (globalSaveState === 'saved' ? '已保存' : '') }}</span></div></form>
                    <form class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" @submit.prevent="saveCheckout">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><h2 class="text-lg font-semibold">Checkout</h2><p class="mt-1 text-sm text-slate-500">由 Checkout Editor 添加区块；这里管理后端开关、Collection 和信任信息。</p></div><label class="flex items-center gap-2 text-sm font-semibold"><input v-model="checkoutForm.enabled" type="checkbox" class="rounded">总开关</label></div>
                        <div class="mt-5 grid gap-4 lg:grid-cols-3"><label class="text-sm font-medium">推荐组件<select v-model="checkoutForm.component_uuid" class="mt-1 w-full rounded-xl border-slate-300"><option value="">暂不选择</option><option v-for="component in checkoutComponents" :key="component.uuid" :value="component.uuid">{{ component.name }} · {{ component.status }}</option></select></label><label class="text-sm font-medium">候选 Collection<select v-model="checkoutForm.shopify_collection_id" class="mt-1 w-full rounded-xl border-slate-300"><option value="">暂不选择</option><option v-for="collection in collections" :key="collection.shopify_collection_id" :value="collection.shopify_collection_id">{{ collection.title }}（{{ collection.product_count }}）</option></select></label><label class="text-sm font-medium">最大推荐数量<input v-model.number="checkoutForm.maximum_recommendations" type="number" min="1" max="1000" placeholder="留空表示遍历整个集合" class="mt-1 w-full rounded-xl border-slate-300"><span class="mt-1 block text-xs text-slate-500">不是固定商品槽位，按 Collection 顺序逐个显示。</span></label></div>
                        <div class="mt-6"><div class="flex items-center justify-between"><h3 class="font-semibold">左侧信任信息</h3><button v-if="checkoutForm.trust_items.length < 6" type="button" class="text-sm font-semibold text-indigo-700" @click="addTrustItem">+ 添加</button></div><div class="mt-3 grid gap-3 lg:grid-cols-2"><div v-for="(item, index) in checkoutForm.trust_items" :key="item.key" class="rounded-xl border border-slate-200 p-4"><div class="flex items-center justify-between"><label class="flex items-center gap-2 text-sm font-semibold"><input v-model="item.enabled" type="checkbox" class="rounded">第 {{ index + 1 }} 项</label><button type="button" class="text-xs text-rose-600" @click="checkoutForm.trust_items.splice(index, 1)">移除</button></div><div class="mt-3 grid gap-3 sm:grid-cols-2"><label class="text-xs">图标<select v-model="item.icon" class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option v-for="icon in checkout.icon_options" :key="icon.value" :value="icon.value">{{ icon.label }}</option></select></label><label class="text-xs">标题<input v-model="item.title" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label><label class="text-xs sm:col-span-2">说明<input v-model="item.description" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label></div></div></div></div><button v-if="permissions.manage" class="mt-5 rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white" :disabled="checkoutForm.processing">{{ checkoutForm.processing ? '保存中…' : '保存 Checkout 设置' }}</button>
                    </form>
                    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div><h2 class="text-lg font-semibold">Smart Cart</h2><p class="mt-1 text-sm text-slate-500">默认关闭；兼容性检查、预览确认和人工启用流程不变。</p></div><button v-if="permissions.manageSmartCart && smartCart?.enabled" type="button" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-700" @click="restoreShopifyCart">一键恢复 Shopify 默认购物车</button></div><div class="mt-5 grid gap-4 xl:grid-cols-3"><form class="rounded-xl bg-slate-50 p-4" @submit.prevent="saveSmartCartDraft"><h3 class="font-semibold">1. 草稿</h3><label class="mt-3 block text-sm">策略<select v-model="smartCartForm.strategy_uuid" class="mt-1 w-full rounded-lg border-slate-300"><option value="">暂不选择</option><option v-for="strategy in strategyRows" :key="strategy.uuid" :value="strategy.uuid">{{ strategy.name }}</option></select></label><label class="mt-3 block text-sm">标题<input v-model="smartCartForm.heading" class="mt-1 w-full rounded-lg border-slate-300"></label><button v-if="permissions.manageSmartCart" class="mt-4 rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white">保存关闭状态草稿</button></form><form class="rounded-xl bg-slate-50 p-4" @submit.prevent="saveSmartCompatibility"><h3 class="font-semibold">2. 测试主题检查</h3><div class="mt-3 grid grid-cols-2 gap-2"><input v-model="smartChecks.theme_name" placeholder="测试主题名称" class="rounded-lg border-slate-300 text-sm"><input v-model="smartChecks.theme_id" placeholder="Theme ID" class="rounded-lg border-slate-300 text-sm"></div><div class="mt-3 space-y-1 text-xs"><label v-for="key in ['unpublished_copy','app_embed_loaded','browser_dialog','cart_link','cart_routes','cart_behaviour_verified']" :key="key" class="flex gap-2"><input v-model="smartChecks[key as keyof typeof smartChecks]" type="checkbox" class="rounded">{{ key }}</label></div><button v-if="permissions.manageSmartCart" class="mt-4 rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white">记录兼容性</button></form><div class="rounded-xl bg-slate-50 p-4"><h3 class="font-semibold">3. 预览与启用</h3><p class="mt-3 text-sm">兼容状态：<strong>{{ smartCart?.compatibility_status ?? 'unchecked' }}</strong></p><p class="mt-2 text-sm">预览：<strong>{{ smartCart?.preview_confirmed_at ? '已确认' : '待确认' }}</strong></p><button v-if="permissions.manageSmartCart && smartCart?.compatibility_status === 'compatible' && !smartCart?.preview_confirmed_at" type="button" class="mt-4 rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-semibold text-indigo-800" @click="confirmSmartCartPreview">确认桌面/移动预览</button><button v-if="permissions.manageSmartCart && smartCart?.compatibility_status === 'compatible' && smartCart?.preview_confirmed_at && !smartCart?.enabled" type="button" class="mt-3 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white" @click="activateSmartCart">人工启用</button></div></div></div>
                    <div class="rounded-2xl border border-indigo-200 bg-indigo-50 p-6 text-sm leading-6 text-indigo-950"><strong>归因规则：</strong>7 天内最后一次推荐点击，仅点击归因；退款或取消自动冲销。该已确认口径为只读设置。</div>
                </div>
            </section>

            <section v-else-if="activeTab === 'strategies'" class="space-y-5">
                <div class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:flex-row lg:items-center lg:justify-between"><div><h2 class="text-lg font-semibold">推荐策略</h2><p class="mt-1 text-sm text-slate-500">草稿自动保存；发布前不会影响线上顾客。</p></div><div class="flex flex-col gap-2 sm:flex-row"><input v-model="search" type="search" placeholder="搜索名称、场景或技术 ID" class="min-w-72 rounded-xl border-slate-300 text-sm"><button type="button" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold" @click="showRecycleBin = !showRecycleBin">回收站 {{ recycledRows.length }}</button><button v-if="permissions.manage" type="button" class="rounded-xl bg-slate-950 px-5 py-2 text-sm font-semibold text-white" @click="createStrategy">制定策略</button></div></div>
                <div v-if="showRecycleBin" class="rounded-2xl border border-violet-200 bg-violet-50 p-5"><div class="mb-3 flex items-center justify-between"><h3 class="font-semibold text-violet-950">回收站</h3><span class="text-xs text-violet-700">删除后保留 30 天</span></div><p v-if="!recycledRows.length" class="text-sm text-violet-700">回收站为空。</p><div v-else class="space-y-2"><div v-for="strategy in recycledRows" :key="strategy.uuid" class="flex items-center justify-between rounded-xl bg-white p-3"><div><p class="font-medium">{{ strategy.name }}</p><p class="text-xs text-slate-500">保留至 {{ formatDate(strategy.recycle_until) }}</p></div><button type="button" class="rounded-lg border border-violet-200 px-3 py-1.5 text-sm font-semibold text-violet-800" @click="restoreStrategy(strategy)">恢复</button></div></div></div>
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><div v-if="!filteredStrategies.length" class="p-14 text-center"><p class="font-semibold text-slate-700">没有匹配策略</p><p class="mt-1 text-sm text-slate-500">点击“制定策略”会立即创建一份独立草稿。</p></div><div v-else class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-5 py-3">名称</th><th class="px-5 py-3">状态</th><th class="px-5 py-3">用于</th><th class="px-5 py-3">创建 / 最后修改</th><th class="px-5 py-3 text-right">操作</th></tr></thead><tbody><tr v-for="strategy in filteredStrategies" :key="strategy.uuid" class="border-t border-slate-100 align-top"><td class="px-5 py-4"><button type="button" class="font-semibold text-slate-950 hover:text-indigo-700" @click="openEditor(strategy)">{{ strategy.name }}</button><p class="mt-1 text-xs text-slate-500">{{ algorithmLabel(strategy.algorithm) }} · 最多 {{ strategy.item_limit }} 个结果</p><p class="mt-1 max-w-56 truncate font-mono text-[10px] text-slate-400" :title="strategy.technical_id">{{ strategy.technical_id }}</p></td><td class="px-5 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="statusClass(strategy.status)">{{ statusLabel(strategy.status) }}</span><p v-if="strategy.has_draft && strategy.published_version" class="mt-2 text-xs text-indigo-700">有未发布草稿</p></td><td class="px-5 py-4"><span v-if="!strategy.used_in.length" class="text-slate-400">没有场景</span><div v-else class="flex max-w-md flex-wrap gap-2"><button v-for="usage in strategy.used_in" :key="usage.component_uuid" type="button" class="rounded-lg border border-slate-200 px-2.5 py-1 text-left text-xs hover:border-indigo-300 hover:bg-indigo-50" @click="openEditor(strategy, 'usage')"><strong>{{ placementLabel(usage.placement) }}</strong><span class="ml-1 text-slate-500">{{ usageLabel(usage.status) }}</span></button></div></td><td class="px-5 py-4 text-xs text-slate-500"><p>{{ formatDate(strategy.created_at) }}</p><p class="mt-2">{{ formatDate(strategy.updated_at) }}</p></td><td class="px-5 py-4"><div class="flex justify-end gap-2"><button type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold" @click="openEditor(strategy)">编辑</button><button type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold" @click="duplicateStrategy(strategy)">复制</button><button v-if="strategy.status === 'enabled'" type="button" class="rounded-lg border border-amber-200 px-3 py-1.5 text-xs font-semibold text-amber-800" @click="disableStrategy(strategy)">停用</button><button type="button" class="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-700" @click="recycleStrategy(strategy)">{{ strategy.used_in.some(item => item.status === 'live') ? '先解除关联' : '归档/删除' }}</button></div></td></tr></tbody></table></div></div>
            </section>

            <section v-else class="space-y-5">
                <div v-if="!permissions.viewAnalytics" class="rounded-2xl border border-slate-200 bg-white p-14 text-center text-slate-500">当前账号没有个性化推荐分析权限。</div>
                <template v-else><div class="rounded-2xl border border-indigo-200 bg-indigo-50 p-5 text-sm text-indigo-950"><strong>归因口径：</strong>7 天内最后一次推荐点击；仅点击归因；退款与取消冲销。数据保留 strategy_id、strategy_version 和 placement/page。</div><div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6"><div v-for="card in analyticsCards" :key="card[0]" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs text-slate-500">{{ card[0] }}</p><p class="mt-2 text-2xl font-semibold">{{ card[1] }}</p></div></div><div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="grid gap-3 md:grid-cols-3"><label class="text-sm">策略<select v-model="analyticsStrategy" class="mt-1 w-full rounded-xl border-slate-300"><option value="">全部策略</option><option v-for="strategy in strategyRows" :key="strategy.uuid" :value="strategy.uuid">{{ strategy.name }}</option></select></label><label class="text-sm">策略版本<select v-model="analyticsVersion" class="mt-1 w-full rounded-xl border-slate-300"><option value="">全部版本</option><option v-for="row in analyticsVersions" :key="row.strategy_version_uuid!" :value="row.strategy_version_uuid!">版本 {{ row.strategy_version }}</option></select></label><label class="text-sm">页面 / 组件<select v-model="analyticsPlacement" class="mt-1 w-full rounded-xl border-slate-300"><option value="">全部位置</option><option v-for="placement in options.placements" :key="placement.value" :value="placement.value">{{ placement.label }}</option></select></label></div></div><div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><div v-if="!analyticsRows.length" class="p-12 text-center text-sm text-slate-500">当前筛选条件没有事件。</div><div v-else class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-4 py-3">策略 / 版本</th><th class="px-4 py-3">页面 / 组件</th><th class="px-4 py-3">曝光</th><th class="px-4 py-3">点击</th><th class="px-4 py-3">加购</th><th class="px-4 py-3">订单</th><th class="px-4 py-3">收入</th></tr></thead><tbody><tr v-for="row in analyticsRows" :key="`${row.strategy_version_uuid}-${row.component_uuid}-${row.placement}`" class="border-t border-slate-100"><td class="px-4 py-3"><strong>{{ row.strategy_name }}</strong><p class="text-xs text-slate-500">版本 {{ row.strategy_version ?? '未记录' }}</p></td><td class="px-4 py-3"><strong>{{ placementLabel(row.placement) }}</strong><p class="text-xs text-slate-500">{{ row.component_name }}</p></td><td class="px-4 py-3">{{ row.impressions }}</td><td class="px-4 py-3">{{ row.clicks }} <span class="text-xs text-slate-400">{{ row.click_through_rate }}%</span></td><td class="px-4 py-3">{{ row.add_to_carts }}</td><td class="px-4 py-3">{{ row.orders }}</td><td class="px-4 py-3 font-semibold">{{ money(row.attributed_revenue, analytics.currency) }}</td></tr></tbody></table></div></div></template>
            </section>
        </div>
        <div v-if="editorOpen" class="fixed inset-0 z-[100] bg-slate-950/45" role="dialog" aria-modal="true" aria-label="策略编辑器">
            <div class="absolute inset-0 flex flex-col bg-slate-50">
                <header class="sticky top-0 z-20 flex min-h-16 items-center justify-between border-b border-slate-200 bg-white px-4 shadow-sm sm:px-6"><div class="min-w-0"><p class="truncate font-semibold text-slate-950">{{ editorDraft?.name || '策略草稿' }}</p><p class="text-xs" :class="saveState === 'failed' ? 'text-rose-700' : 'text-slate-500'"><template v-if="saveState === 'saving'">保存中…</template><template v-else-if="saveState === 'saved'">已保存 {{ formatDate(savedAt) }}</template><template v-else-if="saveState === 'failed'">保存失败，请重试</template><template v-else>等待自动保存</template></p></div><div class="flex items-center gap-2"><button v-if="saveState === 'failed'" type="button" class="rounded-lg border border-rose-200 px-3 py-2 text-sm font-semibold text-rose-700" @click="saveDraft">重试保存</button><button type="button" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white" aria-label="关闭策略编辑器" @click="requestClose">关闭</button></div></header>
                <div class="grid min-h-0 flex-1 lg:grid-cols-[240px_1fr]">
                    <aside class="border-b border-slate-200 bg-white p-3 lg:border-b-0 lg:border-r lg:p-5"><nav class="flex gap-2 overflow-x-auto lg:flex-col"><button v-for="(step, index) in editorSteps" :key="step.value" type="button" class="shrink-0 rounded-xl px-4 py-3 text-left text-sm font-semibold" :class="editorStep === step.value ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-100'" @click="editorStep = step.value"><span class="mr-2 opacity-60">{{ index + 1 }}</span>{{ step.label }}</button></nav></aside>
                    <main class="min-h-0 overflow-y-auto p-4 sm:p-6 lg:p-8">
                        <div v-if="editorLoading" class="mx-auto max-w-4xl rounded-2xl bg-white p-12 text-center text-slate-500">正在读取独立草稿…</div>
                        <div v-else-if="editorError && !editorDraft" class="mx-auto max-w-4xl rounded-2xl border border-rose-200 bg-rose-50 p-8 text-rose-800">{{ editorError }}</div>
                        <div v-else-if="editorDraft" class="mx-auto max-w-5xl space-y-6">
                            <div v-if="editorError" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">{{ editorError }}</div>
                            <section v-if="editorStep === 'basic'" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-xl font-semibold">基本信息</h2><p class="mt-1 text-sm text-slate-500">技术 UUID 稳定不变；商家主要看到名称和状态。</p><div class="mt-6 grid gap-5 md:grid-cols-2"><label class="text-sm font-medium md:col-span-2">策略名称<input v-model="editorDraft.name" maxlength="80" class="mt-1 w-full rounded-xl border-slate-300" placeholder="例如：商品页配件推荐"></label><label class="text-sm font-medium">预设推荐规则<select v-model="editorDraft.algorithm" class="mt-1 w-full rounded-xl border-slate-300"><option v-for="option in options.algorithms" :key="option.value" :value="option.value">{{ option.label }}</option></select></label><label class="text-sm font-medium">最多返回商品数<input v-model.number="editorDraft.item_limit" type="number" min="1" max="50" class="mt-1 w-full rounded-xl border-slate-300"></label></div><div class="mt-5 rounded-xl bg-slate-50 p-4 text-xs text-slate-500">内部 ID：<code>{{ editorStrategy?.technical_id }}</code></div></section>

                            <section v-else-if="editorStep === 'rules'" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-xl font-semibold">确定性推荐规则</h2><p class="mt-1 text-sm text-slate-500">只提供可由 Commerce Hub 商品、库存和订单数据明确计算的预设规则。</p><div class="mt-6 grid gap-5 md:grid-cols-2"><label class="text-sm font-medium">包含标签<textarea :value="joined(editorDraft.configuration.rules.include_tags)" rows="3" class="mt-1 w-full rounded-xl border-slate-300" placeholder="逗号或换行分隔" @input="updateStringList(editorDraft.configuration.rules.include_tags, ($event.target as HTMLTextAreaElement).value)"></textarea></label><label class="text-sm font-medium">排除标签<textarea :value="joined(editorDraft.configuration.rules.exclude_tags)" rows="3" class="mt-1 w-full rounded-xl border-slate-300" @input="updateStringList(editorDraft.configuration.rules.exclude_tags, ($event.target as HTMLTextAreaElement).value)"></textarea></label><label class="text-sm font-medium">排除供应商<textarea :value="joined(editorDraft.configuration.rules.exclude_vendors)" rows="3" class="mt-1 w-full rounded-xl border-slate-300" @input="updateStringList(editorDraft.configuration.rules.exclude_vendors, ($event.target as HTMLTextAreaElement).value)"></textarea></label><label class="text-sm font-medium">排除集合<select v-model="editorDraft.configuration.rules.exclude_collection_ids" multiple class="mt-1 h-28 w-full rounded-xl border-slate-300"><option v-for="collection in collections" :key="collection.shopify_collection_id" :value="collection.shopify_collection_id">{{ collection.title }}</option></select></label></div><div class="mt-5 grid gap-4 sm:grid-cols-3"><label class="text-sm">最低价格<input v-model="editorDraft.configuration.rules.minimum_price" type="number" min="0" class="mt-1 w-full rounded-xl border-slate-300"></label><label class="text-sm">最高价格<input v-model="editorDraft.configuration.rules.maximum_price" type="number" min="0" class="mt-1 w-full rounded-xl border-slate-300"></label><label class="text-sm">最低库存<input v-model.number="editorDraft.configuration.rules.minimum_inventory" type="number" min="0" class="mt-1 w-full rounded-xl border-slate-300"></label></div><div class="mt-5 grid gap-3 sm:grid-cols-3"><label class="flex gap-2 rounded-xl bg-slate-50 p-3 text-sm"><input v-model="editorDraft.configuration.rules.in_stock_only" type="checkbox" class="mt-1 rounded">始终跳过无库存商品</label><label class="flex gap-2 rounded-xl bg-slate-50 p-3 text-sm"><input v-model="editorDraft.configuration.rules.exclude_cart_products" type="checkbox" class="mt-1 rounded">排除购物车已有商品</label><label class="flex gap-2 rounded-xl bg-slate-50 p-3 text-sm"><input v-model="editorDraft.configuration.rules.exclude_purchased_products" type="checkbox" class="mt-1 rounded">排除订单已购商品</label></div></section>

                            <section v-else-if="editorStep === 'products'" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-xl font-semibold">手动选择、置顶与排除</h2><p class="mt-1 text-sm text-slate-500">可以手动选择商品或集合；同一商品只能出现在一个商品列表，不可售商品仍会安全跳过。</p><label class="mt-6 block text-sm font-medium">手动推荐集合<select v-model="editorDraft.configuration.rules.include_collection_ids" multiple class="mt-1 h-32 w-full rounded-xl border-slate-300"><option v-for="collection in collections" :key="collection.shopify_collection_id" :value="collection.shopify_collection_id">{{ collection.title }}（{{ collection.product_count }}）</option></select></label><div class="mt-6 grid gap-5 lg:grid-cols-3"><label v-for="field in [{key:'manual',label:'手动推荐商品'}, {key:'pinned',label:'置顶商品'}, {key:'excluded',label:'排除商品'}]" :key="field.key" class="text-sm font-medium">{{ field.label }}<select v-model="editorDraft.configuration.products[field.key as 'manual'|'pinned'|'excluded']" multiple class="mt-1 h-72 w-full rounded-xl border-slate-300"><option v-for="product in products" :key="product.shopify_product_id" :value="product.shopify_product_id">{{ product.title }}</option></select></label></div></section>

                            <section v-else-if="editorStep === 'discount'" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-xl font-semibold">优惠关联</h2><p class="mt-1 text-sm text-slate-500">仅关联已有折扣，不创建或修改 Shopify 折扣。折扣失效不会阻止基础推荐展示。</p><label class="mt-6 flex items-center gap-3 rounded-xl bg-slate-50 p-4 text-sm font-semibold"><input v-model="editorDraft.configuration.discount.enabled" type="checkbox" class="rounded">启用已有折扣关联</label><label v-if="editorDraft.configuration.discount.enabled" class="mt-4 block text-sm font-medium">已有折扣引用 / 代码<input v-model="editorDraft.configuration.discount.reference" maxlength="255" class="mt-1 w-full rounded-xl border-slate-300" placeholder="填写现有折扣代码或引用"></label></section>

                            <section v-else-if="editorStep === 'usage'" class="space-y-5"><div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-xl font-semibold">使用场景与展示设置</h2><p class="mt-1 text-sm text-slate-500">发布时才切换线上绑定；若目标位置已有其他有效策略，会要求明确确认替换。</p><div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5"><button v-for="placement in options.placements" :key="placement.value" type="button" class="rounded-xl border p-3 text-left text-sm" :class="placementDraft(placement.value)?.enabled ? 'border-indigo-300 bg-indigo-50 text-indigo-900' : 'border-slate-200'" @click="selectedUsagePlacement = placement.value; togglePlacement(placement.value, !placementDraft(placement.value)?.enabled)"><strong>{{ placement.label }}</strong><span class="mt-1 block text-xs">{{ placementDraft(placement.value)?.enabled ? '已加入草稿' : '未使用' }}</span></button></div></div><div v-if="selectedPlacementDraft" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><div class="flex items-center justify-between"><h3 class="font-semibold">{{ placementLabel(selectedPlacementDraft.placement) }}</h3><label class="flex items-center gap-2 text-sm"><input v-model="selectedPlacementDraft.enabled" type="checkbox" class="rounded">启用此场景</label></div><div class="mt-5 grid gap-4 md:grid-cols-2"><label class="text-sm">组件名称<input v-model="selectedPlacementDraft.name" class="mt-1 w-full rounded-xl border-slate-300"></label><label class="text-sm">标题<input v-model="selectedPlacementDraft.heading" class="mt-1 w-full rounded-xl border-slate-300"></label><label class="text-sm">按钮文案<input v-model="selectedPlacementDraft.button_label" class="mt-1 w-full rounded-xl border-slate-300"></label><label class="text-sm">布局<select v-model="selectedPlacementDraft.style.layout" class="mt-1 w-full rounded-xl border-slate-300"><option value="carousel">轮播</option><option value="grid">网格</option></select></label></div><div class="mt-5 grid gap-3 sm:grid-cols-3"><label class="text-sm">桌面列数<input v-model.number="selectedPlacementDraft.style.desktop_columns" type="number" min="1" max="6" class="mt-1 w-full rounded-xl border-slate-300"></label><label class="text-sm">移动列数<input v-model.number="selectedPlacementDraft.style.mobile_columns" type="number" min="1" max="3" class="mt-1 w-full rounded-xl border-slate-300"></label><label class="flex items-end gap-2 rounded-xl bg-slate-50 p-3 text-sm"><input v-model="selectedPlacementDraft.style.show_add_to_cart" type="checkbox" class="rounded">显示加购按钮</label></div><div v-if="selectedPlacementDraft.placement === 'checkout'" class="mt-5 grid gap-4 rounded-xl border border-indigo-100 bg-indigo-50 p-4 md:grid-cols-2"><label class="text-sm">Checkout Collection<select v-model="editorDraft.configuration.checkout.collection_id" class="mt-1 w-full rounded-lg border-indigo-200"><option :value="null">未选择</option><option v-for="collection in collections" :key="collection.shopify_collection_id" :value="collection.shopify_collection_id">{{ collection.title }}</option></select></label><label class="text-sm">最大推荐数量<input v-model.number="editorDraft.configuration.checkout.maximum_recommendations" type="number" min="1" max="1000" placeholder="留空表示遍历整个集合" class="mt-1 w-full rounded-lg border-indigo-200"></label></div></div></section>

                            <section v-else class="space-y-5"><div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div><h2 class="text-xl font-semibold">真实数据预览</h2><p class="mt-1 text-sm text-slate-500">选择真实商品或模拟购物车/已购商品，查看推荐顺序及跳过原因。</p></div><button type="button" class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white" :disabled="previewLoading" @click="loadPreview">{{ previewLoading ? '生成中…' : '生成预览' }}</button></div><div class="mt-5 grid gap-4 md:grid-cols-3"><label class="text-sm">当前商品<select v-model="previewContext.seed_product_id" class="mt-1 w-full rounded-xl border-slate-300"><option value="">无</option><option v-for="product in products" :key="product.shopify_product_id" :value="product.shopify_product_id">{{ product.title }}</option></select></label><label class="text-sm">模拟购物车<select v-model="previewContext.cart_product_ids" multiple class="mt-1 h-28 w-full rounded-xl border-slate-300"><option v-for="product in products" :key="product.shopify_product_id" :value="product.shopify_product_id">{{ product.title }}</option></select></label><label class="text-sm">模拟已购商品<select v-model="previewContext.purchased_product_ids" multiple class="mt-1 h-28 w-full rounded-xl border-slate-300"><option v-for="product in products" :key="product.shopify_product_id" :value="product.shopify_product_id">{{ product.title }}</option></select></label></div><p v-if="previewError" class="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{{ previewError }}</p><div v-if="previewResult" class="mt-6 grid gap-5 lg:grid-cols-[1fr_.7fr]"><div><h3 class="font-semibold">将推荐的商品与顺序</h3><div v-if="!previewResult.items.length" class="mt-3 rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">没有合格候选，店面组件会安全隐藏。</div><ol v-else class="mt-3 space-y-2"><li v-for="(item, index) in previewResult.items" :key="item.shopify_product_id" class="flex items-center gap-3 rounded-xl border border-slate-200 p-3"><span class="flex size-7 items-center justify-center rounded-full bg-slate-950 text-xs font-bold text-white">{{ index + 1 }}</span><img v-if="item.storefront.image.url" :src="item.storefront.image.url" :alt="item.title" class="size-10 rounded-lg object-cover"><div><p class="text-sm font-semibold">{{ item.title }}</p><p class="text-xs text-slate-500">{{ item.reason_code }}</p></div></li></ol><div v-if="editorDraft.configuration.placements.some(item => item.placement === 'checkout' && item.enabled)" class="mt-4 rounded-xl bg-indigo-50 p-4 text-sm text-indigo-900">Checkout 一次显示一个，加入成功后获取下一个；本次最多 {{ previewResult.checkout_sequence.maximum_recommendations ?? '遍历整个集合' }} 个。</div></div><div><h3 class="font-semibold">跳过原因</h3><div v-if="!previewResult.skipped.length" class="mt-3 text-sm text-slate-500">没有被跳过的模拟商品。</div><div v-else class="mt-3 space-y-2"><div v-for="item in previewResult.skipped" :key="item.shopify_product_id" class="rounded-xl bg-slate-50 p-3"><p class="text-sm font-medium">{{ item.title }}</p><p class="text-xs text-slate-500">{{ skipReason(item.reason) }}</p></div></div></div></div></div>
                                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"><div><h2 class="text-xl font-semibold">发布与恢复</h2><p class="mt-1 text-sm text-slate-500">自动保存只更新草稿。明确发布后才切换线上版本。</p></div><button v-if="permissions.manage" type="button" class="rounded-xl bg-emerald-600 px-6 py-3 text-sm font-semibold text-white" @click="publishStrategy(false)">启用 / 发布当前草稿</button></div><div class="mt-6 overflow-hidden rounded-xl border border-slate-200"><table class="min-w-full text-left text-sm"><thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-4 py-3">版本</th><th class="px-4 py-3">状态</th><th class="px-4 py-3">发布时间</th><th class="px-4 py-3"></th></tr></thead><tbody><tr v-for="version in versions" :key="version.uuid" class="border-t border-slate-100"><td class="px-4 py-3">{{ version.version_number }}</td><td class="px-4 py-3">{{ version.is_current ? '当前线上' : version.status }}</td><td class="px-4 py-3">{{ formatDate(version.published_at) }}</td><td class="px-4 py-3 text-right"><button v-if="version.status !== 'draft' && !version.is_current" type="button" class="text-sm font-semibold text-indigo-700" @click="restoreVersion(version)">恢复为新版本</button></td></tr></tbody></table></div></div></section>
                        </div>
                    </main>
                </div>
                <div v-if="closePrompt" class="absolute inset-0 z-30 flex items-center justify-center bg-slate-950/50 p-4"><div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl"><h2 class="text-lg font-semibold">关闭策略编辑器？</h2><p class="mt-2 text-sm leading-6 text-slate-600">草稿会保留。若保存失败，尚未送达服务器的最后修改可能丢失；可以继续编辑并重试。</p><div class="mt-6 flex justify-end gap-3"><button type="button" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold" @click="closePrompt = false">继续编辑</button><button type="button" class="rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white" @click="closeEditor">关闭并保留草稿</button></div></div></div>
                <div v-if="replacementPrompt" class="absolute inset-0 z-30 flex items-center justify-center bg-slate-950/50 p-4"><div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl"><h2 class="text-lg font-semibold">目标场景已有线上策略</h2><p class="mt-2 text-sm leading-6 text-slate-600">第一版同一页面组件只允许一个有效策略。确认后将停用当前绑定，并用这份新版本替换；历史版本仍保留。</p><div class="mt-6 flex justify-end gap-3"><button type="button" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold" @click="replacementPrompt = false">取消</button><button type="button" class="rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white" @click="publishStrategy(true)">确认替换并发布</button></div></div></div>
            </div>
        </div>
    </AppLayout>
</template>
