<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import PersonalizationRevenueChart from '../../Components/Personalization/PersonalizationRevenueChart.vue';

type Algorithm = 'manual' | 'next_llm' | 'free_shipping_upsell' | 'similar_products' | 'substitute_products' | 'best_seller' | 'new_arrivals' | 'frequently_bought_together' | 'frequently_viewed_together' | 'complementary_products' | 'recently_viewed' | 'complete_the_look' | 'same_product_upsell' | 'all_products';
type Placement = 'homepage' | 'product_page' | 'cart_page' | 'smart_cart' | 'checkout' | 'thank_you' | 'order_status';
type StrategyStatus = 'draft' | 'enabled' | 'disabled' | 'configuration_error';
type TopTab = 'overview' | 'analytics' | 'strategies' | 'storefront_defaults' | 'checkout_badges';
type OverviewPlacement = 'product_page' | 'smart_cart' | 'checkout' | 'thank_you' | 'order_status';
type SaveState = 'idle' | 'saving' | 'saved' | 'failed';
type PickerMode = 'manual' | 'pinned' | 'excluded' | 'custom-action' | 'custom-condition' | 'fallback-action';
type RecommendationMode = 'preset' | 'custom';
type RuleMatch = 'all' | 'any';
type RuleOperator = 'contains_any' | 'contains_all' | 'contains_none';
type RuleConditionField = 'cart_product_ids' | 'cart_collection_ids' | 'cart_tags' | 'cart_vendors';
type ActionFilterField = 'product_tags' | 'product_collections' | 'product_vendors';

interface Usage { component_uuid: string; name: string; placement: Placement; status: 'configured_not_enabled' | 'live' | 'disabled' | 'configuration_error' }
interface StrategyRow {
    uuid: string; name: string; technical_id: string; status: StrategyStatus; algorithm: Algorithm; item_limit: number;
    recommendation_mode: RecommendationMode; custom_rule_count: number;
    used_in: Usage[]; created_at: string | null; updated_at: string | null;
    published_version: { uuid: string; version_number: number; published_at: string | null } | null; has_draft: boolean;
}
interface ProductSelection {
    shopify_product_id: string; product_gid: string; variant_gid: string; minimum_quantity: number;
    selected_at: string; position: number;
}
interface RuleCondition { id: string; field: RuleConditionField; operator: RuleOperator; values: string[] }
interface ActionFilter { id: string; field: ActionFilterField; operator: RuleOperator; values: string[] }
interface RuleAction { type: 'manual'; products: ProductSelection[]; filters: ActionFilter[] }
interface CustomRule { id: string; name: string; priority: number; match: RuleMatch; conditions: RuleCondition[]; exit_on_match: boolean; action: RuleAction }
interface RecommendationRule {
    mode: RecommendationMode;
    preset: Algorithm;
    custom: { rules: CustomRule[]; fallback: { enabled: boolean; action: RuleAction } };
}
interface PlacementDraft {
    placement: Placement; enabled: boolean; component_uuid: string | null; name: string; heading: string; button_label: string;
    style: { layout: 'carousel' | 'grid'; desktop_columns: number; mobile_columns: number; show_image: boolean; show_vendor: boolean; show_price: boolean; show_compare_at_price: boolean; show_add_to_cart: boolean; tokens: Record<string, never> };
}
interface StrategyDraft {
    uuid: string; version_number: number; status: string; name: string; algorithm: Algorithm; item_limit: number; lock_version: number; updated_at: string | null;
    configuration: {
        recommendation_rule: RecommendationRule;
        rules: { include_tags: string[]; exclude_tags: string[]; include_collection_ids: string[]; exclude_collection_ids: string[]; exclude_vendors: string[]; exclude_purchase_options: string[]; minimum_price: string | null; maximum_price: string | null; minimum_inventory: number | null; in_stock_only: boolean; exclude_cart_products: boolean; exclude_purchased_products: boolean };
       products: { manual: ProductSelection[]; pinned: ProductSelection[]; excluded: string[] };
        discount: { enabled: boolean; reference: string | null; title?: string | null; summary?: string | null; code?: string | null; status?: string | null; percentage?: number | null; validated_at?: string | null };
        placements: PlacementDraft[];
        checkout: { maximum_recommendations: number | null; collection_id: string | null };
    };
}
interface ProductOption {
    shopify_product_id: string; shopify_gid: string; title: string; handle: string; vendor: string | null; image_url: string | null; price: string | null; currency: string;
    status: string; available_for_sale: boolean; availability_label: string; tags: string[]; collection_ids: string[];
    variants: Array<{ shopify_variant_id: string; shopify_gid: string; title: string; sku: string | null; price: string; available_for_sale: boolean; selected_options: Array<{ name?: string; value?: string }> }>;
}
interface CheckoutTrustItem { key: string; icon: string; title: string; description: string; position: number; enabled: boolean }
interface ComponentOption { uuid: string; strategy_uuid: string; strategy_name: string; name: string; placement: Placement; status: string; heading: string | null; button_label: string | null }
interface DiscountOption { id: string; title: string; summary: string; status: string; code: string; percentage: number | null; editable: boolean }

const props = defineProps<{
    organization: { id: number; name: string };
    store: { id: number; name: string; shopify_domain: string; currency: string };
    strategyRows: StrategyRow[];
    strategies: Array<{ uuid: string; name: string; algorithm: Algorithm; enabled: boolean }>;
    components: ComponentOption[]; products: ProductOption[];
    collections: Array<{ shopify_collection_id: string; title: string; handle: string; sort_order: string | null; product_count: number }>;
    checkout: { uuid: string | null; strategy_uuid: string | null; thank_you: { uuid: string | null; strategy_uuid: string | null; heading: string; enabled: boolean }; order_status: { uuid: string | null; strategy_uuid: string | null; heading: string; enabled: boolean }; trust_items: CheckoutTrustItem[]; icon_options: Array<{ value: string; label: string }> };
    smartCart: { uuid: string; strategy_uuid: string | null; enabled: boolean; compatibility_status: string; compatibility_details: { checks?: Array<{ key: string; label: string; passed: boolean; details: string | null }> }; compatibility_checked_at: string | null; theme_id: string | null; theme_name: string | null; preview_confirmed_at: string | null; fallback_mode: string; settings: Record<string, unknown> } | null;
    globalSettings: { default_locale: 'zh-CN' | 'en'; copy: { recommendation_heading: string; add_button: string; checkout_heading: string }; attribution: { model: string; window_days: number; click_only: boolean; refund_cancel_reversal: boolean } };
    options: { algorithms: Array<{ value: Algorithm; label: string }>; placements: Array<{ value: Placement; label: string }> };
    permissions: { manage: boolean; manageSmartCart: boolean; viewAnalytics: boolean };
    analytics: { status: string; period: { days: number; from: string; to: string; timezone: string }; currency: string; impressions: number; clicks: number; add_to_carts: number; orders: number; attributed_revenue: string; aov: string; quantity: number; sales: string; discounts: string; revenue: string; click_through_rate: number; add_to_cart_rate: number; reversed_orders: number; excluded_currency_orders: number; attribution: { model: string; window_days: number; click_only: boolean; refund_cancel_reversal: boolean }; daily: Array<{ date: string; quantity: number; sales: string; discounts: string; revenue: string }>; dimensions: Array<{ strategy_uuid: string | null; strategy_name: string; strategy_version_uuid: string | null; strategy_version: number | null; component_uuid: string | null; component_name: string; placement: string; impressions: number; clicks: number; add_to_carts: number; orders: number; attributed_revenue: string; quantity: number; sales: string; discounts: string; revenue: string; click_through_rate: number }> };
}>();

const cloneJson = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T;
const baseUrl = `/organizations/${props.organization.id}/stores/${props.store.id}/personalization`;
const csrf = () => document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
const requestId = () => globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-0000-4000-8000-${Math.random().toString(16).slice(2).padEnd(12, '0').slice(0, 12)}`;
class ApiError extends Error { constructor(public code: string, message: string, public status: number) { super(message); } }
async function requestJson<T>(url: string, options: RequestInit = {}): Promise<T> {
    const response = await fetch(url, { ...options, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), ...(options.headers ?? {}) } });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        const validation = payload?.errors ? Object.values(payload.errors).flat().join('; ') : '';
        throw new ApiError(payload?.error?.code ?? 'REQUEST_FAILED', payload?.error?.message ?? validation ?? 'The request failed. Try again.', response.status);
    }
    return payload.data as T;
}

const activeTab = ref<TopTab>('overview');
const tabs = [
    { value: 'overview' as const, label: 'Overview', hint: 'Storefront status and placements' },
    { value: 'analytics' as const, label: 'Analytics', hint: 'Revenue and sales performance' },
    { value: 'strategies' as const, label: 'Strategies', hint: 'Create and manage recommendations' },
    { value: 'storefront_defaults' as const, label: 'Storefront defaults', hint: 'Language and fallback copy' },
    { value: 'checkout_badges' as const, label: 'Checkout trust badges', hint: 'Trust content and preview' },
];
const strategyRows = ref(props.strategyRows.map(row => cloneJson(row)));
watch(() => props.strategyRows, rows => { strategyRows.value = rows.map(row => cloneJson(row)); }, { deep: true });
const placementLabel = (value: string) => props.options.placements.find(option => option.value === value)?.label ?? value;
const statusLabel = (value: StrategyStatus) => ({ draft: 'Draft', enabled: 'Enabled', disabled: 'Disabled', configuration_error: 'Configuration error' }[value]);
const statusClass = (value: StrategyStatus) => ({ draft: 'bg-slate-100 text-slate-700', enabled: 'bg-emerald-100 text-emerald-800', disabled: 'bg-amber-100 text-amber-800', configuration_error: 'bg-rose-100 text-rose-800' }[value]);
const usageLabel = (value: Usage['status']) => ({ configured_not_enabled: 'Configured, not enabled', live: 'Live', disabled: 'Disabled', configuration_error: 'Configuration error' }[value]);
const formatDate = (value: string | null) => value ? new Intl.DateTimeFormat('en-US', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—';
const money = (value: string | null, currency = props.store.currency) => value === null ? '—' : new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(Number(value));
const search = ref('');
const filteredStrategies = computed(() => { const keyword = search.value.trim().toLocaleLowerCase(); return strategyRows.value.filter(row => !keyword || [row.name, ...row.used_in.map(item => item.name)].some(value => value.toLocaleLowerCase().includes(keyword))); });

const editorOpen = ref(false);
const editorStrategy = ref<StrategyRow | null>(null);
const editorDraft = ref<StrategyDraft | null>(null);
const editorLoading = ref(false);
const editorError = ref('');
const saveState = ref<SaveState>('idle');
const savedAt = ref('');
const closePrompt = ref(false);
let autosaveTimer: ReturnType<typeof setTimeout> | null = null;
let ignoreDraftWatch = false;
let pendingSave: Promise<void> | null = null;

function selection(value: unknown, position = 0): ProductSelection {
    const fallbackId = typeof value === 'string' ? value : String((value as Partial<ProductSelection>)?.shopify_product_id ?? '');
    const row = value as Partial<ProductSelection>;
    const option = props.products.find(item => item.shopify_product_id === fallbackId);
    const variant = option?.variants.find(item => item.available_for_sale) ?? option?.variants[0];
    return {
        shopify_product_id: fallbackId,
        product_gid: row?.product_gid || option?.shopify_gid || `gid://shopify/Product/${fallbackId}`,
        variant_gid: row?.variant_gid || variant?.shopify_gid || '',
        minimum_quantity: Math.max(1, Number(row?.minimum_quantity ?? 1)),
        selected_at: row?.selected_at || new Date().toISOString(),
        position: position + 1,
    };
}
function emptyAction(): RuleAction { return { type: 'manual', products: [], filters: [] }; }
function defaultRecommendationRule(preset: Algorithm = 'manual'): RecommendationRule {
    return { mode: 'preset', preset, custom: { rules: [], fallback: { enabled: false, action: emptyAction() } } };
}
function normalizeRuleAction(action: Partial<RuleAction> | undefined): RuleAction {
    return {
        type: 'manual',
       products: (action?.products ?? []).map((item, index) => selection(item, index)).filter(item => item.shopify_product_id),
        filters: (action?.filters ?? []).map(filter => ({
            id: filter.id || requestId(),
            field: filter.field || 'product_tags',
            operator: filter.operator || 'contains_any',
            values: Array.isArray(filter.values) ? filter.values.map(String) : [],
        })),
    };
}
function normalizeEditorPayload(payload: { strategy: StrategyRow; draft: StrategyDraft }) {
    ignoreDraftWatch = true;
    const draft = cloneJson(payload.draft);
    draft.item_limit = 24;
    draft.configuration.recommendation_rule ??= defaultRecommendationRule(draft.algorithm);
    const recommendationRule = draft.configuration.recommendation_rule;
    recommendationRule.mode = recommendationRule.mode === 'custom' ? 'custom' : 'preset';
    recommendationRule.preset = props.options.algorithms.some(option => option.value === recommendationRule.preset) ? recommendationRule.preset : 'manual';
    recommendationRule.custom ??= defaultRecommendationRule().custom;
    recommendationRule.custom.rules = (recommendationRule.custom.rules ?? []).map((rule, index) => ({
        id: rule.id || requestId(),
        name: String(rule.name || `Rule ${index + 1}`).slice(0, 50),
        priority: index + 1,
        match: rule.match === 'any' ? 'any' : 'all',
        conditions: (rule.conditions ?? []).map(condition => ({
            id: condition.id || requestId(),
            field: condition.field || 'cart_product_ids',
            operator: condition.operator || 'contains_any',
            values: Array.isArray(condition.values) ? condition.values.map(String) : [],
        })),
        exit_on_match: Boolean(rule.exit_on_match),
        action: normalizeRuleAction(rule.action),
    }));
    recommendationRule.custom.fallback ??= { enabled: false, action: emptyAction() };
    recommendationRule.custom.fallback.action = normalizeRuleAction(recommendationRule.custom.fallback.action);
    draft.algorithm = recommendationRule.mode === 'preset' ? recommendationRule.preset : 'manual';
    draft.configuration.rules.exclude_purchase_options ??= [];
    draft.configuration.products.manual = (draft.configuration.products.manual ?? []).map((item, index) => selection(item, index)).filter(row => row.shopify_product_id);
    draft.configuration.products.pinned = (draft.configuration.products.pinned ?? []).map((item, index) => selection(item, index)).filter(row => row.shopify_product_id);
    excludeSpecificEnabled.value = draft.configuration.products.excluded.length > 0;
    excludeTagsEnabled.value = draft.configuration.rules.exclude_tags.length > 0;
    excludeCollectionsEnabled.value = draft.configuration.rules.exclude_collection_ids.length > 0;
    excludeVendorsEnabled.value = draft.configuration.rules.exclude_vendors.length > 0;
    excludePurchaseOptionsEnabled.value = draft.configuration.rules.exclude_purchase_options.length > 0;
    editorStrategy.value = cloneJson(payload.strategy); editorDraft.value = draft; saveState.value = 'saved'; savedAt.value = draft.updated_at ?? '';
    queueMicrotask(() => { ignoreDraftWatch = false; });
}
async function createStrategy() {
    editorOpen.value = true; editorLoading.value = true; editorError.value = '';
    try { const payload = await requestJson<{ strategy: StrategyRow; draft: StrategyDraft }>(`${baseUrl}/strategy-workflow/drafts`, { method: 'POST', body: JSON.stringify({ idempotency_key: requestId() }) }); normalizeEditorPayload(payload); upsertStrategy(payload.strategy); }
    catch (error) { editorError.value = error instanceof Error ? error.message : 'Unable to create the strategy.'; }
    finally { editorLoading.value = false; }
}
async function openEditor(strategy: StrategyRow) {
    editorOpen.value = true; editorLoading.value = true; editorError.value = '';
    try { normalizeEditorPayload(await requestJson(`${baseUrl}/strategy-workflow/${strategy.uuid}`)); }
    catch (error) { editorError.value = error instanceof Error ? error.message : 'Unable to load the strategy.'; }
    finally { editorLoading.value = false; }
}
function scheduleAutosave() { if (!editorOpen.value || !editorDraft.value || ignoreDraftWatch) return; saveState.value = 'idle'; if (autosaveTimer) clearTimeout(autosaveTimer); autosaveTimer = setTimeout(() => { void saveDraft(); }, 800); }
watch(editorDraft, scheduleAutosave, { deep: true });
async function saveDraft() {
    if (!editorStrategy.value || !editorDraft.value) return;
    if (pendingSave) return pendingSave;
    if (autosaveTimer) { clearTimeout(autosaveTimer); autosaveTimer = null; }
    saveState.value = 'saving';
    const snapshot = cloneJson(editorDraft.value);
    snapshot.algorithm = snapshot.configuration.recommendation_rule.mode === 'preset'
        ? snapshot.configuration.recommendation_rule.preset
        : 'manual';
    snapshot.item_limit = 24;
    pendingSave = (async () => {
        try {
            const payload = await requestJson<{ draft: StrategyDraft; saved_at: string }>(`${baseUrl}/strategy-workflow/${editorStrategy.value!.uuid}/draft`, { method: 'PATCH', body: JSON.stringify({ idempotency_key: requestId(), lock_version: snapshot.lock_version, draft: snapshot }) });
            const row = strategyRows.value.find(item => item.uuid === editorStrategy.value?.uuid);
            if (row) {
                row.name = payload.draft.name;
                row.updated_at = payload.saved_at;
                row.algorithm = payload.draft.algorithm;
                row.recommendation_mode = payload.draft.configuration.recommendation_rule.mode;
                row.custom_rule_count = payload.draft.configuration.recommendation_rule.custom.rules.length;
            }
            normalizeEditorPayload({ strategy: { ...editorStrategy.value!, name: payload.draft.name, updated_at: payload.saved_at }, draft: payload.draft }); savedAt.value = payload.saved_at; saveState.value = 'saved'; editorError.value = '';
        } catch (error) { saveState.value = 'failed'; editorError.value = error instanceof Error ? error.message : 'Autosave failed.'; }
        finally { pendingSave = null; queueMicrotask(() => { ignoreDraftWatch = false; }); }
    })();
    return pendingSave;
}
async function requestClose() { if (saveState.value === 'saving' || saveState.value === 'idle') await saveDraft(); if ((saveState.value as SaveState) === 'failed') { closePrompt.value = true; return; } closeEditor(); }
function closeEditor() { if (autosaveTimer) clearTimeout(autosaveTimer); autosaveTimer = null; editorOpen.value = false; editorDraft.value = null; editorStrategy.value = null; closePrompt.value = false; editorError.value = ''; }
function upsertStrategy(row: StrategyRow) { const index = strategyRows.value.findIndex(item => item.uuid === row.uuid); if (index < 0) strategyRows.value.unshift(cloneJson(row)); else strategyRows.value.splice(index, 1, cloneJson(row)); }
const pageNotice = ref('');
async function duplicateStrategy(strategy: StrategyRow) {
    pageNotice.value = '';
    try { const payload = await requestJson<{ strategy: StrategyRow; draft: StrategyDraft }>(`${baseUrl}/strategy-workflow/${strategy.uuid}/duplicate`, { method: 'POST', body: JSON.stringify({ idempotency_key: requestId() }) }); upsertStrategy(payload.strategy); editorOpen.value = true; normalizeEditorPayload(payload); }
    catch (error) { pageNotice.value = error instanceof Error ? error.message : 'Unable to duplicate the strategy.'; }
}

const pickerMode = ref<PickerMode | null>(null);
const pickerRuleId = ref('');
const pickerConditionId = ref('');
const pickerSearch = ref('');
const pickerSelections = ref<ProductSelection[]>([]);
const pickerError = ref('');
const filteredProducts = computed(() => { const keyword = pickerSearch.value.trim().toLocaleLowerCase(); return props.products.filter(item => !keyword || [item.title, item.handle, item.availability_label].some(value => value.toLocaleLowerCase().includes(keyword))); });
const customRules = computed(() => editorDraft.value?.configuration.recommendation_rule.custom.rules ?? []);
function customRule(id: string) { return customRules.value.find(rule => rule.id === id); }
const pickerAllowsQuantity = computed(() => !['excluded', 'custom-condition'].includes(pickerMode.value ?? ''));
const pickerTitle = computed(() => ({
    manual: 'Select recommended products', pinned: 'Select pinned products', excluded: 'Select excluded products',
    'custom-action': 'Select action products', 'custom-condition': 'Select condition products', 'fallback-action': 'Select fallback products',
}[pickerMode.value ?? 'manual']));
function openProductPicker(mode: PickerMode, ruleId = '', conditionId = '') {
    if (!editorDraft.value) return;
    pickerMode.value = mode; pickerRuleId.value = ruleId; pickerConditionId.value = conditionId; pickerSearch.value = ''; pickerError.value = '';
    if (mode === 'excluded') pickerSelections.value = editorDraft.value.configuration.products.excluded.map((id, index) => selection(id, index));
    else if (mode === 'custom-action') pickerSelections.value = cloneJson(customRule(ruleId)?.action.products ?? []);
    else if (mode === 'fallback-action') pickerSelections.value = cloneJson(editorDraft.value.configuration.recommendation_rule.custom.fallback.action.products);
    else if (mode === 'custom-condition') {
        const condition = customRule(ruleId)?.conditions.find(item => item.id === conditionId);
        pickerSelections.value = (condition?.values ?? []).map((id, index) => selection(id, index));
    } else pickerSelections.value = cloneJson(editorDraft.value.configuration.products[mode]);
}
function isPickerSelected(id: string) { return pickerSelections.value.some(row => row.shopify_product_id === id); }
function togglePickerProduct(id: string) { const index = pickerSelections.value.findIndex(row => row.shopify_product_id === id); if (index >= 0) { pickerSelections.value.splice(index, 1); return; } if (pickerSelections.value.length >= 24) { pickerError.value = 'You can select up to 24 products.'; return; } pickerSelections.value.push(selection(id, pickerSelections.value.length)); }
function updatePickerQuantity(id: string, value: unknown) { const row = pickerSelections.value.find(item => item.shopify_product_id === id); if (row) row.minimum_quantity = Math.min(999, Math.max(1, Number(value) || 1)); }
function updatePickerVariant(id: string, gid: string) { const row = pickerSelections.value.find(item => item.shopify_product_id === id); if (row) row.variant_gid = gid; }
function closeProductPicker() { pickerMode.value = null; pickerRuleId.value = ''; pickerConditionId.value = ''; pickerSelections.value = []; pickerError.value = ''; }
function confirmProductPicker() {
    if (!editorDraft.value || !pickerMode.value || pickerSelections.value.length === 0) return;
    const normalized = pickerSelections.value.map((item, index) => ({ ...item, position: index + 1 }));
    if (pickerMode.value === 'excluded') editorDraft.value.configuration.products.excluded = normalized.map(row => row.shopify_product_id);
    else if (pickerMode.value === 'custom-action') {
        const rule = customRule(pickerRuleId.value); if (rule) rule.action.products = cloneJson(normalized);
    } else if (pickerMode.value === 'fallback-action') editorDraft.value.configuration.recommendation_rule.custom.fallback.action.products = cloneJson(normalized);
    else if (pickerMode.value === 'custom-condition') {
        const condition = customRule(pickerRuleId.value)?.conditions.find(item => item.id === pickerConditionId.value);
        if (condition) condition.values = normalized.map(row => row.shopify_product_id);
    } else {
        if (pickerMode.value === 'pinned') {
            const union = new Set([
                ...editorDraft.value.configuration.products.manual.map(row => row.shopify_product_id),
                ...pickerSelections.value.map(row => row.shopify_product_id),
            ]);
            if (union.size > 24) { pickerError.value = 'The combined pinned and recommended product total cannot exceed 24.'; return; }
        }
        editorDraft.value.configuration.products[pickerMode.value] = cloneJson(normalized);
        if (pickerMode.value === 'pinned') for (const pinned of normalized) if (!editorDraft.value.configuration.products.manual.some(row => row.shopify_product_id === pinned.shopify_product_id)) editorDraft.value.configuration.products.manual.push({ ...cloneJson(pinned), position: editorDraft.value.configuration.products.manual.length + 1 });
    }
    closeProductPicker();
}
function product(id: string) { return props.products.find(item => item.shopify_product_id === id); }
function removeProduct(mode: 'manual' | 'pinned' | 'excluded', id: string) { if (!editorDraft.value) return; if (mode === 'excluded') editorDraft.value.configuration.products.excluded = editorDraft.value.configuration.products.excluded.filter(item => item !== id); else editorDraft.value.configuration.products[mode] = editorDraft.value.configuration.products[mode].filter(item => item.shopify_product_id !== id); if (mode === 'manual') editorDraft.value.configuration.products.pinned = editorDraft.value.configuration.products.pinned.filter(item => item.shopify_product_id !== id); }
const manualError = computed(() => editorDraft.value
    && editorDraft.value.configuration.recommendation_rule.mode === 'preset'
    && editorDraft.value.configuration.recommendation_rule.preset === 'manual'
    && editorDraft.value.configuration.products.manual.length === 0 ? 'Select at least one recommended product.' : '');
const excludeHistory = computed({ get: () => Boolean(editorDraft.value?.configuration.rules.exclude_cart_products && editorDraft.value?.configuration.rules.exclude_purchased_products), set: (value: boolean) => { if (editorDraft.value) { editorDraft.value.configuration.rules.exclude_cart_products = value; editorDraft.value.configuration.rules.exclude_purchased_products = value; } } });
const excludeSpecificEnabled = ref(false); const excludeTagsEnabled = ref(false); const excludeCollectionsEnabled = ref(false); const excludeVendorsEnabled = ref(false); const excludePurchaseOptionsEnabled = ref(false);
watch(excludeSpecificEnabled, enabled => { if (!enabled && editorDraft.value) editorDraft.value.configuration.products.excluded = []; });
watch(excludeTagsEnabled, enabled => { if (!enabled && editorDraft.value) editorDraft.value.configuration.rules.exclude_tags = []; });
watch(excludeCollectionsEnabled, enabled => { if (!enabled && editorDraft.value) editorDraft.value.configuration.rules.exclude_collection_ids = []; });
watch(excludeVendorsEnabled, enabled => { if (!enabled && editorDraft.value) editorDraft.value.configuration.rules.exclude_vendors = []; });
watch(excludePurchaseOptionsEnabled, enabled => { if (!enabled && editorDraft.value) editorDraft.value.configuration.rules.exclude_purchase_options = []; });
const availableTags = computed(() => [...new Set(props.products.flatMap(item => item.tags))].sort());
const availableVendors = computed(() => [...new Set(props.products.map(item => item.vendor).filter((value): value is string => Boolean(value)))].sort());
const purchaseOptions = computed(() => [...new Set(props.products.flatMap(item => item.variants.flatMap(variant => variant.selected_options.map(option => `${option.name ?? ''}: ${option.value ?? ''}`.trim()).filter(value => value !== ':'))))].sort());

const customRuleEditorOpen = ref(false);
const activeCustomRuleId = ref('');
const customRuleDeleteTarget = ref<CustomRule | null>(null);
const customRuleDeleteError = ref('');
let draggedRuleIndex: number | null = null;
let draggedProductIndex: number | null = null;
const activeCustomRule = computed(() => customRule(activeCustomRuleId.value) ?? customRules.value[0] ?? null);
const customRuleError = computed(() => {
    if (!editorDraft.value || editorDraft.value.configuration.recommendation_rule.mode !== 'custom') return '';
    if (customRules.value.length === 0) return 'Add at least one custom rule, or enable and configure the fallback rule.';
    if (!customRules.value.some(rule => rule.action.products.length > 0)
        && !editorDraft.value.configuration.recommendation_rule.custom.fallback.action.products.length) return 'Select recommended products for at least one rule or the fallback rule.';
    return '';
});
const hasUnavailableManualProducts = computed(() => editorDraft.value?.configuration.products.manual.some(item => !product(item.shopify_product_id)?.available_for_sale) ?? false);
function newCondition(): RuleCondition { return { id: requestId(), field: 'cart_product_ids', operator: 'contains_any', values: [] }; }
function newCustomRule(position: number): CustomRule {
    return { id: requestId(), name: `Rule ${position}`, priority: position, match: 'all', conditions: [newCondition()], exit_on_match: false, action: emptyAction() };
}
function setRecommendationMode(mode: RecommendationMode) {
    if (!editorDraft.value) return;
    editorDraft.value.configuration.recommendation_rule.mode = mode;
    if (mode === 'custom' && customRules.value.length === 0) addCustomRule();
}
function openCustomRuleEditor() {
    if (!editorDraft.value) return;
    setRecommendationMode('custom');
    activeCustomRuleId.value = customRules.value[0]?.id ?? '';
    customRuleEditorOpen.value = true;
}
function closeCustomRuleEditor() { customRuleEditorOpen.value = false; customRuleDeleteTarget.value = null; }
function addCustomRule() {
    if (!editorDraft.value || customRules.value.length >= 20) return;
    const rule = newCustomRule(customRules.value.length + 1);
    editorDraft.value.configuration.recommendation_rule.custom.rules.push(rule);
    activeCustomRuleId.value = rule.id;
}
function renumberRules() { customRules.value.forEach((rule, index) => { rule.priority = index + 1; }); }
function moveCustomRule(index: number, offset: number) {
    const target = index + offset;
    if (target < 0 || target >= customRules.value.length) return;
    const [rule] = customRules.value.splice(index, 1); customRules.value.splice(target, 0, rule); renumberRules();
}
function startRuleDrag(index: number) { draggedRuleIndex = index; }
function dropRule(index: number) {
    if (draggedRuleIndex === null || draggedRuleIndex === index) { draggedRuleIndex = null; return; }
    const [rule] = customRules.value.splice(draggedRuleIndex, 1); customRules.value.splice(index, 0, rule); draggedRuleIndex = null; renumberRules();
}
function addCondition(rule: CustomRule) { if (rule.conditions.length < 10) rule.conditions.push(newCondition()); }
function removeCondition(rule: CustomRule, id: string) { rule.conditions = rule.conditions.filter(condition => condition.id !== id); }
function addActionFilter(action: RuleAction) { if (action.filters.length < 10) action.filters.push({ id: requestId(), field: 'product_tags', operator: 'contains_any', values: [] }); }
function removeActionFilter(action: RuleAction, id: string) { action.filters = action.filters.filter(filter => filter.id !== id); }
function askDeleteCustomRule(rule: CustomRule) { customRuleDeleteTarget.value = rule; customRuleDeleteError.value = ''; }
function confirmDeleteCustomRule() {
    if (!customRuleDeleteTarget.value) return;
    const id = customRuleDeleteTarget.value.id;
    const index = customRules.value.findIndex(rule => rule.id === id);
    if (index >= 0) customRules.value.splice(index, 1);
    renumberRules(); activeCustomRuleId.value = customRules.value[Math.min(index, customRules.value.length - 1)]?.id ?? '';
    customRuleDeleteTarget.value = null;
}
function ruleSummary(rule: CustomRule) {
    if (!rule.conditions.length) return 'No conditions added';
    const labels = rule.conditions.slice(0, 2).map(condition => `${conditionFieldLabel(condition.field)} ${operatorLabel(condition.operator)} ${condition.values.length}`);
    return labels.join(rule.match === 'all' ? ' AND ' : ' OR ') + (rule.conditions.length > 2 ? ` and ${rule.conditions.length} ` : '');
}
function conditionFieldLabel(field: RuleConditionField) { return ({ cart_product_ids: 'Products in cart', cart_collection_ids: 'Cart product collections', cart_tags: 'Cart product tags', cart_vendors: 'Cart product vendors' }[field]); }
function actionFilterLabel(field: ActionFilterField) { return ({ product_tags: 'Product tags', product_collections: 'Product collections', product_vendors: 'Product vendors' }[field]); }
function operatorLabel(operator: RuleOperator) { return ({ contains_any: 'Contains any', contains_all: 'Contains all', contains_none: 'Contains none' }[operator]); }
function moveProductSelection(items: ProductSelection[], index: number, offset: number) {
    const target = index + offset; if (target < 0 || target >= items.length) return;
    const [item] = items.splice(index, 1); items.splice(target, 0, item); items.forEach((row, position) => { row.position = position + 1; });
}
function startProductDrag(index: number) { draggedProductIndex = index; }
function dropProductSelection(items: ProductSelection[], index: number) {
    if (draggedProductIndex === null || draggedProductIndex === index) { draggedProductIndex = null; return; }
    const [item] = items.splice(draggedProductIndex, 1); items.splice(index, 0, item); draggedProductIndex = null;
    items.forEach((row, position) => { row.position = position + 1; });
}
function removeActionProduct(action: RuleAction, id: string) { action.products = action.products.filter(item => item.shopify_product_id !== id); action.products.forEach((item, index) => { item.position = index + 1; }); }
function optionValues(field: RuleConditionField | ActionFilterField) {
    if (field === 'cart_collection_ids' || field === 'product_collections') return props.collections.map(item => ({ value: item.shopify_collection_id, label: item.title }));
    if (field === 'cart_vendors' || field === 'product_vendors') return availableVendors.value.map(value => ({ value, label: value }));
    return availableTags.value.map(value => ({ value, label: value }));
}

const discounts = ref<DiscountOption[]>([]); const discountModal = ref(false); const discountLoading = ref(false); const discountError = ref('');
const discountForm = reactive({ id: '', title: '10% off', code: 'DECO10', percentage: 10 });
const discountProductIds = computed(() => {
    if (!editorDraft.value) return [];
    const recommendation = editorDraft.value.configuration.recommendation_rule;
    const candidates = recommendation.mode === 'custom'
        ? [...recommendation.custom.rules.flatMap(rule => rule.action.products), ...recommendation.custom.fallback.action.products]
        : editorDraft.value.configuration.products.manual;
    return [...new Set([...editorDraft.value.configuration.products.pinned, ...candidates].map(item => item.shopify_product_id))];
});
async function openDiscounts() { discountModal.value = true; discountLoading.value = true; discountError.value = ''; try { discounts.value = await requestJson(`${baseUrl}/discounts`); } catch (error) { discountError.value = error instanceof Error ? error.message : 'Unable to load Shopify discounts.'; } finally { discountLoading.value = false; } }
function editDiscount(discount?: DiscountOption) { discountForm.id = discount?.id ?? ''; discountForm.title = discount?.title ?? '10% off'; discountForm.code = discount?.code ?? 'DECO10'; discountForm.percentage = discount?.percentage ?? 10; }
async function saveDiscount() {
    if (!editorDraft.value || discountProductIds.value.length === 0) { discountError.value = 'Select recommended products first.'; return; }
    discountLoading.value = true; discountError.value = '';
    try { const payload = await requestJson<DiscountOption>(`${baseUrl}/discounts`, { method: discountForm.id ? 'PATCH' : 'POST', body: JSON.stringify({ ...discountForm, product_ids: discountProductIds.value }) }); const index = discounts.value.findIndex(item => item.id === payload.id); if (index >= 0) discounts.value.splice(index, 1, payload); else discounts.value.unshift(payload); chooseDiscount(payload); editDiscount(); }
    catch (error) { discountError.value = error instanceof Error ? error.message : 'Unable to save the Shopify discount.'; }
    finally { discountLoading.value = false; }
}
function chooseDiscount(discount: DiscountOption) { if (!editorDraft.value) return; editorDraft.value.configuration.discount = { enabled: true, reference: discount.id, title: discount.title, summary: discount.summary, code: discount.code, status: discount.status, percentage: discount.percentage, validated_at: new Date().toISOString() }; discountModal.value = false; }
const selectedDiscount = computed(() => discounts.value.find(item => item.id === editorDraft.value?.configuration.discount.reference));

const deleteTarget = ref<StrategyRow | null>(null); const deleteProcessing = ref(false); const deleteError = ref('');
function askDelete(strategy: StrategyRow) { deleteTarget.value = strategy; deleteError.value = ''; }
function closeDelete() { if (!deleteProcessing.value) { deleteTarget.value = null; deleteError.value = ''; } }
async function confirmDelete() { if (!deleteTarget.value || deleteProcessing.value) return; deleteProcessing.value = true; deleteError.value = ''; try { await requestJson(`${baseUrl}/strategy-workflow/${deleteTarget.value.uuid}`, { method: 'DELETE', body: JSON.stringify({ idempotency_key: requestId() }) }); const uuid = deleteTarget.value.uuid; strategyRows.value = strategyRows.value.filter(item => item.uuid !== uuid); if (editorStrategy.value?.uuid === uuid) closeEditor(); deleteTarget.value = null; } catch (error) { deleteError.value = error instanceof Error ? error.message : 'Unable to delete the strategy.'; } finally { deleteProcessing.value = false; } }
function onKeydown(event: KeyboardEvent) { if (event.key !== 'Escape') return; if (customRuleDeleteTarget.value) { event.preventDefault(); customRuleDeleteTarget.value = null; return; } if (deleteTarget.value) { event.preventDefault(); closeDelete(); return; } if (pickerMode.value) { event.preventDefault(); closeProductPicker(); return; } if (discountModal.value) { event.preventDefault(); discountModal.value = false; return; } if (customRuleEditorOpen.value) { event.preventDefault(); return; } if (editorOpen.value) { event.preventDefault(); closePrompt.value = true; } }
onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => { window.removeEventListener('keydown', onKeydown); if (autosaveTimer) clearTimeout(autosaveTimer); });

const globalForm = reactive(cloneJson(props.globalSettings)); const globalSaveState = ref<SaveState>('idle'); const globalError = ref('');
const fallbackCopy = {
    en: { recommendation_heading: 'You may also like', add_button: 'Add to cart', checkout_heading: 'Great Value Bundles for You' },
    'zh-CN': { recommendation_heading: '你可能还喜欢', add_button: '加入购物车', checkout_heading: '为你推荐超值组合' },
};
async function saveGlobalSettings() {
    globalSaveState.value = 'saving'; globalError.value = '';
    globalForm.copy = cloneJson(fallbackCopy[globalForm.default_locale]);
    try { await requestJson(`${baseUrl}/global-settings`, { method: 'PUT', body: JSON.stringify({ default_locale: globalForm.default_locale, copy: globalForm.copy }) }); globalSaveState.value = 'saved'; }
    catch (error) { globalSaveState.value = 'failed'; globalError.value = error instanceof Error ? error.message : 'Save failed.'; }
}
const checkoutForm = useForm({ strategy_uuid: props.checkout.strategy_uuid ?? '', trust_items: props.checkout.trust_items.map(item => ({ ...item })) });
function addTrustItem() { if (checkoutForm.trust_items.length < 6) checkoutForm.trust_items.push({ key: `trust_${Date.now()}`, icon: 'check-circle', title: '', description: '', position: checkoutForm.trust_items.length + 1, enabled: true }); }
function moveTrustItem(index: number, offset: number) {
    const target = index + offset;
    if (target < 0 || target >= checkoutForm.trust_items.length) return;
    const [item] = checkoutForm.trust_items.splice(index, 1);
    checkoutForm.trust_items.splice(target, 0, item);
    checkoutForm.trust_items.forEach((row, position) => { row.position = position + 1; });
}
const iconGlyph = (icon: string) => ({ store: '▣', truck: '→', star: '★', 'check-circle': '✓', lock: '●', savings: '%', delivered: '✓', return: '↩', info: 'i' }[icon] ?? 'i');
const enabledTrustItems = computed(() => checkoutForm.trust_items.filter(item => item.enabled && item.title.trim()).slice(0, 6));
const checkoutSaveState = ref<SaveState>('idle');
const checkoutMessage = ref('');
function saveCheckout(mode: 'binding' | 'badges' = 'binding') {
    checkoutSaveState.value = 'saving';
    checkoutMessage.value = '';
    const strategyName = strategyRows.value.find(strategy => strategy.uuid === checkoutForm.strategy_uuid)?.name ?? 'selected strategy';
    checkoutForm
        .transform(data => ({ strategy_uuid: data.strategy_uuid, trust_items: data.trust_items.map((item, index) => ({ ...item, position: index + 1 })) }))
        .put(`${baseUrl}/checkout`, {
            preserveScroll: true,
            onSuccess: page => {
                const flash = page.props.flash as { error?: string | null } | undefined;
                if (flash?.error) {
                    checkoutSaveState.value = 'failed';
                    checkoutMessage.value = flash.error;
                    return;
                }
                checkoutSaveState.value = 'saved';
                checkoutMessage.value = mode === 'badges' ? 'Checkout trust badges saved.' : `Bound to Checkout: ${strategyName}`;
            },
            onError: errors => {
                checkoutSaveState.value = 'failed';
                checkoutMessage.value = Object.values(errors).join('; ') || 'Checkout Strategy save failed. Try again.';
            },
            onFinish: () => {
                if (checkoutSaveState.value === 'saving') checkoutSaveState.value = 'idle';
            },
        });
}
const smartCartForm = useForm({ strategy_uuid: props.smartCart?.strategy_uuid ?? '', heading: String(props.smartCart?.settings?.heading ?? 'Cart recommendations') });
function saveSmartCartDraft() { smartCartForm.put(`${baseUrl}/smart-cart`, { preserveScroll: true }); }
const thankYouForm = useForm({ strategy_uuid: props.checkout.thank_you.strategy_uuid ?? '', heading: props.checkout.thank_you.heading || 'Great Value Bundles for You' });
function saveThankYou() { thankYouForm.put(`${baseUrl}/thank-you`, { preserveScroll: true }); }
const orderStatusForm = useForm({ strategy_uuid: props.checkout.order_status.strategy_uuid ?? '', heading: props.checkout.order_status.heading || 'Great Value Bundles for You' });
function saveOrderStatus() { orderStatusForm.put(`${baseUrl}/order-status`, { preserveScroll: true }); }
const configurationAlerts = computed(() => strategyRows.value.filter(row => row.status === 'configuration_error'));
const selectedPlacement = ref<OverviewPlacement | null>(null);
const productPageComponent = computed(() => props.components.find(component => component.placement === 'product_page' && component.status === 'active') ?? props.components.find(component => component.placement === 'product_page'));
const placementHasActivity = (placement: OverviewPlacement) => props.analytics.dimensions.some(row => row.placement === placement && (row.impressions > 0 || row.clicks > 0 || row.add_to_carts > 0 || row.orders > 0));
const placementRows = computed(() => [
    { key: 'product_page' as const, label: 'Product page', delivery: 'Theme app block', strategy: productPageComponent.value?.strategy_name ?? 'Not assigned', configured: productPageComponent.value?.status === 'active', live: placementHasActivity('product_page') },
    { key: 'smart_cart' as const, label: 'Cart', delivery: 'Native cart drawer', strategy: strategyRows.value.find(row => row.uuid === smartCartForm.strategy_uuid)?.name ?? 'Not assigned', configured: Boolean(props.smartCart?.enabled && smartCartForm.strategy_uuid), live: placementHasActivity('smart_cart') },
    { key: 'checkout' as const, label: 'Checkout', delivery: 'Checkout extension', strategy: strategyRows.value.find(row => row.uuid === checkoutForm.strategy_uuid)?.name ?? 'Not assigned', configured: Boolean(checkoutForm.strategy_uuid), live: placementHasActivity('checkout') },
    { key: 'thank_you' as const, label: 'Thank you page', delivery: 'Checkout extension', strategy: strategyRows.value.find(row => row.uuid === thankYouForm.strategy_uuid)?.name ?? 'Not assigned', configured: Boolean(thankYouForm.strategy_uuid && props.checkout.thank_you.enabled), live: placementHasActivity('thank_you') },
    { key: 'order_status' as const, label: 'Order status', delivery: 'Customer account extension', strategy: strategyRows.value.find(row => row.uuid === orderStatusForm.strategy_uuid)?.name ?? 'Not assigned', configured: Boolean(orderStatusForm.strategy_uuid && props.checkout.order_status.enabled), live: placementHasActivity('order_status') },
].map(row => ({ ...row, status: row.live ? 'Live' : (row.configured ? 'Configured' : 'Not configured') })));
const livePlacementCount = computed(() => placementRows.value.filter(row => row.live).length);
const configuredPlacementCount = computed(() => placementRows.value.filter(row => row.configured).length);
const overviewIssues = computed(() => [
    ...placementRows.value.filter(row => row.status === 'Not configured').map(row => ({ key: row.key, title: row.label, details: 'No recommendation strategy is assigned to this placement.' })),
    ...(props.permissions.viewAnalytics && props.analytics.status === 'active' ? placementRows.value.filter(row => row.status === 'Configured').map(row => ({ key: row.key, title: row.label, details: 'Configured, but no storefront activity was observed during the selected analytics period. Confirm that the extension block is enabled.' })) : []),
    ...configurationAlerts.value.map(row => ({ key: 'strategy' as const, title: row.name, details: 'This strategy has an invalid component assignment.' })),
]);
function editPlacement(key: OverviewPlacement) { selectedPlacement.value = key; }
function fixOverviewIssue(issue: { key: OverviewPlacement | 'strategy' }) {
    if (issue.key === 'strategy') activeTab.value = 'strategies';
    else editPlacement(issue.key);
}
function openProductPageStrategy() {
    const component = productPageComponent.value;
    const strategy = component ? strategyRows.value.find(row => row.uuid === component.strategy_uuid) : null;
    if (strategy) openEditor(strategy); else activeTab.value = 'strategies';
}
const analyticsStrategy = ref(''); const analyticsVersion = ref(''); const analyticsPlacement = ref('');
const analyticsRows = computed(() => props.analytics.dimensions.filter(row => (row.quantity > 0 || Number(row.sales) > 0 || Number(row.discounts) > 0 || Number(row.revenue) > 0) && (!analyticsStrategy.value || row.strategy_uuid === analyticsStrategy.value) && (!analyticsVersion.value || row.strategy_version_uuid === analyticsVersion.value) && (!analyticsPlacement.value || row.placement === analyticsPlacement.value)));
const analyticsVersions = computed(() => props.analytics.dimensions.filter(row => !analyticsStrategy.value || row.strategy_uuid === analyticsStrategy.value).filter((row, index, rows) => row.strategy_version_uuid && rows.findIndex(item => item.strategy_version_uuid === row.strategy_version_uuid) === index));
const analyticsCards = computed(() => [['Quantity', props.analytics.quantity.toLocaleString('en-US')], ['Sales', money(props.analytics.sales, props.analytics.currency)], ['Discounts', money(props.analytics.discounts, props.analytics.currency)], ['Revenue', money(props.analytics.revenue, props.analytics.currency)]]);
const analyticsFrom = ref(props.analytics.period.from);
const analyticsTo = ref(props.analytics.period.to);
const analyticsDateError = ref('');
const analyticsToday = new Intl.DateTimeFormat('en-CA', { timeZone: props.analytics.period.timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
watch(() => props.analytics.period, period => { analyticsFrom.value = period.from; analyticsTo.value = period.to; }, { deep: true });
const revenuePoints = computed(() => props.analytics.daily.map(row => ({ date: row.date, revenue: row.revenue })));
function applyAnalyticsDates() {
    const from = new Date(`${analyticsFrom.value}T00:00:00Z`);
    const to = new Date(`${analyticsTo.value}T00:00:00Z`);
    const days = Math.floor((to.getTime() - from.getTime()) / 86_400_000) + 1;
    if (!analyticsFrom.value || !analyticsTo.value || days < 1 || days > 90) {
        analyticsDateError.value = 'Select a date range of up to 90 days.';
        return;
    }
    analyticsDateError.value = '';
    router.get(baseUrl, { from: analyticsFrom.value, to: analyticsTo.value }, { preserveScroll: true, preserveState: true, replace: true, only: ['analytics'] });
}
</script>

<template>
    <Head title="Deco Personalization" />
    <AppLayout>
        <div class="mx-auto max-w-[1480px] space-y-6 p-4 sm:p-6 lg:p-8">
            <header class="overflow-hidden rounded-3xl bg-slate-950 px-6 py-7 text-white shadow-xl sm:px-8">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                    <div><p class="text-xs font-semibold uppercase tracking-[.22em] text-indigo-300">Deco Personalization</p><h1 class="mt-2 text-3xl font-semibold">Personalization</h1><p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">Manage deterministic recommendations using real products, inventory, and order data from Commerce Hub. Strategies save automatically and appear where a page or component is assigned.</p></div>
                    <div class="rounded-2xl bg-white/10 px-4 py-3 text-sm"><p class="font-semibold">{{ store.name }}</p><p class="mt-1 text-xs text-slate-300">{{ store.shopify_domain }}</p></div>
                </div>
            </header>
            <nav class="grid gap-2 rounded-2xl border border-slate-200 bg-white p-2 shadow-sm sm:grid-cols-2 xl:grid-cols-6" aria-label="Personalization navigation">
                <button v-for="tab in tabs" :key="tab.value" type="button" class="rounded-xl px-3 py-3 text-left transition" :class="activeTab === tab.value ? 'bg-slate-950 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-50'" @click="activeTab = tab.value"><span class="block text-sm font-semibold">{{ tab.label }}</span><span class="mt-0.5 hidden text-xs opacity-70 sm:block">{{ tab.hint }}</span></button>
                <a :href="`${baseUrl}/editor`" class="rounded-xl px-3 py-3 text-left text-slate-600 transition hover:bg-slate-50"><span class="block text-sm font-semibold">Widget editor</span><span class="mt-0.5 hidden text-xs opacity-70 sm:block">Display, styles and live preview</span></a>
            </nav>
            <p v-if="pageNotice" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">{{ pageNotice }}</p>

            <section v-if="activeTab === 'overview'" class="space-y-6">
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-sm font-medium text-slate-500">Personalization status</p>
                        <p class="mt-2 text-2xl font-semibold">{{ livePlacementCount ? 'Live' : (configuredPlacementCount ? 'Configured' : 'Setup required') }}</p>
                        <p class="mt-2 text-sm text-slate-500">{{ livePlacementCount }} live · {{ configuredPlacementCount }} configured</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-sm font-medium text-slate-500">Active strategies</p>
                        <p class="mt-2 text-2xl font-semibold">{{ strategyRows.filter(row => row.status === 'enabled').length }}</p>
                        <p class="mt-2 text-sm text-slate-500">{{ strategyRows.length }} total strategies</p>
                    </div>
                    <button type="button" class="rounded-2xl border border-slate-200 bg-white p-5 text-left shadow-sm transition hover:border-indigo-300" @click="permissions.viewAnalytics && (activeTab = 'analytics')">
                        <p class="text-sm font-medium text-slate-500">Revenue · {{ analytics.period.days }} days</p>
                        <p class="mt-2 text-2xl font-semibold">{{ money(analytics.revenue, analytics.currency) }}</p>
                        <p class="mt-2 text-sm text-indigo-700">View in Analytics</p>
                    </button>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-sm font-medium text-slate-500">Needs attention</p>
                        <p class="mt-2 text-2xl font-semibold">{{ overviewIssues.length }}</p>
                        <p class="mt-2 text-sm text-slate-500">Unconfigured placements and invalid strategies</p>
                    </div>
                </div>

                <div class="grid gap-6 xl:grid-cols-[1.35fr_.65fr]">
                    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div class="border-b border-slate-100 p-5">
                            <h2 class="text-lg font-semibold">Storefront placements</h2>
                            <p class="mt-1 text-sm text-slate-500">See what customers can currently see and configure each placement directly.</p>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-left text-sm">
                                <thead class="bg-slate-50 text-sm text-slate-500"><tr><th class="px-5 py-3">Placement</th><th class="px-5 py-3">Strategy</th><th class="px-5 py-3">Status</th><th class="px-5 py-3 text-right">Action</th></tr></thead>
                                <tbody>
                                    <tr v-for="row in placementRows" :key="row.key" class="border-t border-slate-100">
                                        <td class="px-5 py-4"><strong>{{ row.label }}</strong><p class="mt-1 text-sm text-slate-500">{{ row.delivery }}</p></td>
                                        <td class="px-5 py-4">{{ row.strategy }}</td>
                                        <td class="px-5 py-4"><span class="rounded-full px-2.5 py-1 text-sm font-semibold" :class="row.live ? 'bg-emerald-100 text-emerald-800' : (row.status === 'Configured' ? 'bg-indigo-100 text-indigo-800' : 'bg-amber-100 text-amber-800')">{{ row.status }}</span></td>
                                        <td class="px-5 py-4 text-right"><button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold hover:border-indigo-400 hover:text-indigo-700" @click="editPlacement(row.key)">{{ row.status === 'Not configured' ? 'Configure' : 'Edit' }}</button></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-4"><div><h2 class="text-lg font-semibold">Setup and issues</h2><p class="mt-1 text-sm text-slate-500">Only unfinished or actionable items appear here.</p></div><span class="rounded-full px-3 py-1 text-sm font-semibold" :class="overviewIssues.length ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800'">{{ overviewIssues.length || 'Healthy' }}</span></div>
                        <div v-if="overviewIssues.length" class="mt-5 space-y-3">
                            <div v-for="issue in overviewIssues" :key="`${issue.key}-${issue.title}`" class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                                <strong class="text-sm text-amber-950">{{ issue.title }}</strong>
                                <p class="mt-1 text-sm leading-6 text-amber-900">{{ issue.details }}</p>
                                <button type="button" class="mt-3 text-sm font-semibold text-indigo-700" @click="fixOverviewIssue(issue)">Fix now</button>
                            </div>
                        </div>
                        <div v-else class="mt-6 rounded-xl bg-emerald-50 p-4 text-sm leading-6 text-emerald-900">All configured placements are healthy. Product and order data remain scoped to this store.</div>
                    </div>
                </div>

                <div v-if="selectedPlacement" class="rounded-2xl border border-indigo-200 bg-white p-6 shadow-sm">
                    <div class="flex items-start justify-between gap-4">
                        <div><p class="text-sm font-semibold uppercase tracking-wider text-indigo-600">Placement settings</p><h2 class="mt-1 text-xl font-semibold">{{ placementRows.find(row => row.key === selectedPlacement)?.label }}</h2></div>
                        <button type="button" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold" @click="selectedPlacement = null">Close</button>
                    </div>

                    <div v-if="selectedPlacement === 'product_page'" class="mt-5 rounded-xl bg-slate-50 p-5">
                        <p class="text-sm leading-6 text-slate-600">Product page placement is managed inside a strategy so its product rules, heading, layout, and publishing state stay together.</p>
                        <button type="button" class="mt-4 rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white" @click="openProductPageStrategy">{{ productPageComponent ? 'Edit assigned strategy' : 'Open strategies' }}</button>
                    </div>

                    <form v-else-if="selectedPlacement === 'checkout'" class="mt-5 grid gap-4 rounded-xl bg-slate-50 p-5 md:grid-cols-[minmax(0,1fr)_auto] md:items-end" @submit.prevent="saveCheckout('binding')">
                        <label class="text-sm font-medium">Recommendation strategy<select v-model="checkoutForm.strategy_uuid" required class="mt-1 w-full rounded-xl border-slate-300"><option disabled value="">Select a strategy</option><option v-for="strategy in strategyRows" :key="strategy.uuid" :value="strategy.uuid">{{ strategy.name }}</option></select></label>
                        <button v-if="permissions.manage" type="submit" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50" :disabled="checkoutForm.processing || !checkoutForm.strategy_uuid">{{ checkoutForm.processing ? 'Saving…' : 'Save Checkout placement' }}</button>
                        <p class="text-sm text-slate-500 md:col-span-2">The Checkout extension shows one eligible recommendation at a time and recalculates after an item is added.</p>
                    </form>

                    <form v-else-if="selectedPlacement === 'thank_you'" class="mt-5 grid gap-4 rounded-xl bg-slate-50 p-5 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end" @submit.prevent="saveThankYou">
                        <label class="text-sm font-medium">Strategy<select v-model="thankYouForm.strategy_uuid" required class="mt-1 w-full rounded-xl border-slate-300"><option disabled value="">Select a strategy</option><option v-for="strategy in strategyRows" :key="strategy.uuid" :value="strategy.uuid">{{ strategy.name }}</option></select></label>
                        <label class="text-sm font-medium">Header<input v-model="thankYouForm.heading" class="mt-1 w-full rounded-xl border-slate-300" maxlength="120"></label>
                        <button v-if="permissions.manage" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50" :disabled="thankYouForm.processing || !thankYouForm.strategy_uuid">{{ thankYouForm.processing ? 'Saving…' : 'Save placement' }}</button>
                    </form>

                    <form v-else-if="selectedPlacement === 'order_status'" class="mt-5 grid gap-4 rounded-xl bg-slate-50 p-5 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end" @submit.prevent="saveOrderStatus">
                        <label class="text-sm font-medium">Strategy<select v-model="orderStatusForm.strategy_uuid" required class="mt-1 w-full rounded-xl border-slate-300"><option disabled value="">Select a strategy</option><option v-for="strategy in strategyRows" :key="strategy.uuid" :value="strategy.uuid">{{ strategy.name }}</option></select></label>
                        <label class="text-sm font-medium">Header<input v-model="orderStatusForm.heading" class="mt-1 w-full rounded-xl border-slate-300" maxlength="120"></label>
                        <button v-if="permissions.manage" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50" :disabled="orderStatusForm.processing || !orderStatusForm.strategy_uuid">{{ orderStatusForm.processing ? 'Saving…' : 'Save placement' }}</button>
                    </form>

                    <form v-else class="mt-5 grid gap-4 rounded-xl bg-slate-50 p-5 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end" @submit.prevent="saveSmartCartDraft">
                        <label class="text-sm font-medium">Strategy<select v-model="smartCartForm.strategy_uuid" class="mt-1 w-full rounded-xl border-slate-300"><option value="">None</option><option v-for="strategy in strategyRows" :key="strategy.uuid" :value="strategy.uuid">{{ strategy.name }}</option></select></label>
                        <label class="text-sm font-medium">Header<input v-model="smartCartForm.heading" class="mt-1 w-full rounded-xl border-slate-300" maxlength="120"></label>
                        <button v-if="permissions.manageSmartCart" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50" :disabled="smartCartForm.processing">{{ smartCartForm.processing ? 'Saving…' : 'Save placement' }}</button>
                    </form>

                    <p v-if="checkoutMessage && selectedPlacement === 'checkout'" class="mt-3 text-sm" :class="checkoutSaveState === 'failed' ? 'text-rose-700' : 'text-emerald-700'">{{ checkoutMessage }}</p>
                </div>
            </section>

            <section v-else-if="activeTab === 'strategies'" class="space-y-5">
                <div class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:flex-row lg:items-center lg:justify-between"><div><h2 class="text-lg font-semibold">Recommendation strategy</h2><p class="mt-1 text-sm text-slate-500">New and edited strategies save automatically. Each component controls where it appears.</p></div><div class="flex flex-col gap-2 sm:flex-row"><input v-model="search" type="search" placeholder="Search strategy name or placement" class="min-w-72 rounded-xl border-slate-300 text-sm"><button v-if="permissions.manage" type="button" class="rounded-xl bg-slate-950 px-5 py-2 text-sm font-semibold text-white" @click="createStrategy">Create strategy</button></div></div>
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><div v-if="!filteredStrategies.length" class="p-14 text-center"><p class="font-semibold text-slate-700">No matching strategies</p><p class="mt-1 text-sm text-slate-500">Select Create strategy to create your first recommendation strategy.</p></div><div v-else class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-5 py-3">Name</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">Used in</th><th class="px-5 py-3">Created / last updated</th><th class="px-5 py-3 text-right">Actions</th></tr></thead><tbody><tr v-for="strategy in filteredStrategies" :key="strategy.uuid" class="border-t border-slate-100 align-top"><td class="px-5 py-4"><button type="button" class="font-semibold text-slate-950 hover:text-indigo-700" @click="openEditor(strategy)">{{ strategy.name }}</button><p class="mt-1 text-xs text-slate-500">{{ strategy.recommendation_mode === 'custom' ? `Custom rules (${strategy.custom_rule_count})` : (options.algorithms.find(option => option.value === strategy.algorithm)?.label ?? 'Preset rule') }}</p></td><td class="px-5 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="statusClass(strategy.status)">{{ statusLabel(strategy.status) }}</span></td><td class="px-5 py-4"><span v-if="!strategy.used_in.length" class="text-slate-400">No placements</span><div v-else class="flex max-w-md flex-wrap gap-2"><span v-for="usage in strategy.used_in" :key="usage.component_uuid" class="rounded-lg border border-slate-200 px-2.5 py-1 text-xs"><strong>{{ placementLabel(usage.placement) }}</strong><span class="ml-1 text-slate-500">{{ usageLabel(usage.status) }}</span></span></div></td><td class="px-5 py-4 text-xs text-slate-500"><p>{{ formatDate(strategy.created_at) }}</p><p class="mt-2">{{ formatDate(strategy.updated_at) }}</p></td><td class="px-5 py-4"><div class="flex justify-end gap-2"><button type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold" @click="openEditor(strategy)">Edit</button><button type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold" @click="duplicateStrategy(strategy)">Duplicate</button><button type="button" class="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-700" @click="askDelete(strategy)">Delete</button></div></td></tr></tbody></table></div></div>
            </section>

            <section v-else-if="activeTab === 'analytics'" class="space-y-5">
                <div v-if="!permissions.viewAnalytics" class="rounded-2xl border border-slate-200 bg-white p-14 text-center text-slate-500">Your account does not have permission to view personalization analytics.</div>
                <template v-else>
                    <div class="rounded-2xl border border-indigo-200 bg-indigo-50 p-5 text-sm text-indigo-950"><strong>Attribution:</strong> Last recommendation click within 7 days. Click-through attribution only; refunds and cancellations are reversed.</div>
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <div v-for="card in analyticsCards" :key="card[0]" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm text-slate-500">{{ card[0] }}</p><p class="mt-2 text-2xl font-semibold">{{ card[1] }}</p></div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                            <div><h2 class="text-lg font-semibold">Personalization attributed revenue</h2><p class="mt-1 text-sm text-slate-500">Revenue from orders attributed to a recommendation click.</p></div>
                            <form class="flex flex-wrap items-end gap-3" @submit.prevent="applyAnalyticsDates">
                                <label class="text-sm font-medium">From<input v-model="analyticsFrom" type="date" :max="analyticsToday" required class="mt-1 block rounded-xl border-slate-300 text-sm"></label>
                                <label class="text-sm font-medium">To<input v-model="analyticsTo" type="date" :max="analyticsToday" required class="mt-1 block rounded-xl border-slate-300 text-sm"></label>
                                <button type="submit" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white">Apply</button>
                            </form>
                        </div>
                        <p v-if="analyticsDateError" class="mt-3 text-sm text-rose-700">{{ analyticsDateError }}</p>
                        <p class="mt-3 text-sm text-slate-500">{{ analytics.period.from }} to {{ analytics.period.to }} · {{ analytics.period.timezone }}</p>
                        <div class="mt-5"><PersonalizationRevenueChart :points="revenuePoints" :currency="analytics.currency" /></div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="grid gap-3 md:grid-cols-3">
                            <label class="text-sm">Strategy<select v-model="analyticsStrategy" class="mt-1 w-full rounded-xl border-slate-300"><option value="">All strategies</option><option v-for="strategy in strategyRows" :key="strategy.uuid" :value="strategy.uuid">{{ strategy.name }}</option></select></label>
                            <label class="text-sm">Strategy version<select v-model="analyticsVersion" class="mt-1 w-full rounded-xl border-slate-300"><option value="">All versions</option><option v-for="row in analyticsVersions" :key="row.strategy_version_uuid!" :value="row.strategy_version_uuid!">Version {{ row.strategy_version }}</option></select></label>
                            <label class="text-sm">Page / component<select v-model="analyticsPlacement" class="mt-1 w-full rounded-xl border-slate-300"><option value="">All placements</option><option v-for="placement in options.placements" :key="placement.value" :value="placement.value">{{ placement.label }}</option></select></label>
                        </div>
                    </div>
                    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div v-if="!analyticsRows.length" class="p-12 text-center text-sm text-slate-500">No attributed sales match the current filters.</div>
                        <div v-else class="overflow-x-auto"><table class="min-w-full text-left text-sm"><thead class="bg-slate-50 text-sm text-slate-500"><tr><th class="px-4 py-3">Strategy / version</th><th class="px-4 py-3">Page / component</th><th class="px-4 py-3">Quantity</th><th class="px-4 py-3">Sales</th><th class="px-4 py-3">Discounts</th><th class="px-4 py-3">Revenue</th></tr></thead><tbody><tr v-for="row in analyticsRows" :key="`${row.strategy_version_uuid}-${row.component_uuid}-${row.placement}`" class="border-t border-slate-100"><td class="px-4 py-3"><strong>{{ row.strategy_name }}</strong><p class="text-sm text-slate-500">Version {{ row.strategy_version ?? 'Not recorded' }}</p></td><td class="px-4 py-3"><strong>{{ placementLabel(row.placement) }}</strong><p class="text-sm text-slate-500">{{ row.component_name }}</p></td><td class="px-4 py-3">{{ row.quantity.toLocaleString('en-US') }}</td><td class="px-4 py-3">{{ money(row.sales, analytics.currency) }}</td><td class="px-4 py-3">{{ money(row.discounts, analytics.currency) }}</td><td class="px-4 py-3 font-semibold">{{ money(row.revenue, analytics.currency) }}</td></tr></tbody></table></div>
                    </div>
                </template>
            </section>

            <section v-else-if="activeTab === 'storefront_defaults'" class="space-y-5">
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="max-w-3xl">
                        <h2 class="text-xl font-semibold">Storefront defaults</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">Choose the fallback language for storefront recommendation text. Headers remain configured separately for each placement, so there is only one global setting here.</p>
                    </div>
                    <form class="mt-6 max-w-xl" @submit.prevent="saveGlobalSettings">
                        <label class="block text-sm font-medium">Default language
                            <select v-model="globalForm.default_locale" class="mt-1 w-full rounded-xl border-slate-300">
                                <option value="en">English</option>
                                <option value="zh-CN">Simplified Chinese</option>
                            </select>
                            <span class="mt-2 block text-sm leading-6 text-slate-500">This supplies fallback button and recommendation text only when a placement does not provide its own copy. It does not translate strategy names, products, or the DecoAdmin interface.</span>
                        </label>
                        <div class="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <p class="text-sm font-semibold">Automatic fallback copy</p>
                            <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                                <div><dt class="text-slate-500">Recommendation</dt><dd class="mt-1 font-medium">{{ fallbackCopy[globalForm.default_locale].recommendation_heading }}</dd></div>
                                <div><dt class="text-slate-500">Button</dt><dd class="mt-1 font-medium">{{ fallbackCopy[globalForm.default_locale].add_button }}</dd></div>
                            </dl>
                        </div>
                        <div class="mt-5 flex items-center gap-3">
                            <button v-if="permissions.manage" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50" :disabled="globalSaveState === 'saving'">{{ globalSaveState === 'saving' ? 'Saving…' : 'Save language' }}</button>
                            <span class="text-sm" :class="globalSaveState === 'failed' ? 'text-rose-700' : 'text-emerald-700'">{{ globalError || (globalSaveState === 'saved' ? 'Saved' : '') }}</span>
                        </div>
                    </form>
                </div>
            </section>

            <section v-else class="space-y-5">
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div><h2 class="text-xl font-semibold">Checkout trust badges</h2><p class="mt-2 max-w-3xl text-sm leading-6 text-slate-500">Choose Shopify Checkout icons manually, write the customer-facing text, arrange the order, and preview the three-column Checkout layout.</p></div>
                        <button v-if="permissions.manage && checkoutForm.trust_items.length < 6" type="button" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold" @click="addTrustItem">Add badge</button>
                    </div>

                    <div class="mt-6 grid gap-6 xl:grid-cols-[1.35fr_.65fr]">
                        <div class="space-y-3">
                            <div v-for="(item, index) in checkoutForm.trust_items" :key="item.key" class="rounded-xl border border-slate-200 p-4">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <label class="flex items-center gap-2 text-sm font-semibold"><input v-model="item.enabled" type="checkbox" class="rounded">Badge {{ index + 1 }}</label>
                                    <div class="flex items-center gap-2">
                                        <button type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm disabled:opacity-30" :disabled="index === 0" @click="moveTrustItem(index, -1)">Move up</button>
                                        <button type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm disabled:opacity-30" :disabled="index === checkoutForm.trust_items.length - 1" @click="moveTrustItem(index, 1)">Move down</button>
                                        <button type="button" class="rounded-lg px-3 py-1.5 text-sm font-semibold text-rose-700" @click="checkoutForm.trust_items.splice(index, 1)">Remove</button>
                                    </div>
                                </div>
                                <div class="mt-4 grid gap-4 md:grid-cols-[140px_minmax(0,1fr)_minmax(0,1fr)]">
                                    <label class="text-sm font-medium">Shopify icon
                                        <span class="mt-1 flex items-center gap-2"><span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-lg font-semibold text-slate-700" aria-hidden="true">{{ iconGlyph(item.icon) }}</span><select v-model="item.icon" class="min-w-0 flex-1 rounded-xl border-slate-300 text-sm"><option v-for="icon in checkout.icon_options" :key="icon.value" :value="icon.value">{{ icon.label }}</option></select></span>
                                    </label>
                                    <label class="text-sm font-medium">Heading<input v-model="item.title" class="mt-1 w-full rounded-xl border-slate-300 text-sm" maxlength="80"></label>
                                    <label class="text-sm font-medium">Description<input v-model="item.description" class="mt-1 w-full rounded-xl border-slate-300 text-sm" maxlength="120"></label>
                                </div>
                            </div>
                            <p v-if="!checkoutForm.trust_items.length" class="rounded-xl bg-slate-50 p-5 text-sm text-slate-500">No badges configured. Add a badge to begin.</p>
                            <div class="flex flex-wrap items-center gap-3 pt-2">
                                <button v-if="permissions.manage" type="button" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50" :disabled="checkoutForm.processing || !checkoutForm.strategy_uuid" @click="saveCheckout('badges')">{{ checkoutForm.processing ? 'Saving…' : 'Save badges' }}</button>
                                <span v-if="!checkoutForm.strategy_uuid" class="text-sm text-amber-700">Configure the Checkout placement before saving badges.</span>
                                <span v-else-if="checkoutMessage" class="text-sm" :class="checkoutSaveState === 'failed' ? 'text-rose-700' : 'text-emerald-700'">{{ checkoutMessage }}</span>
                            </div>
                        </div>

                        <aside class="rounded-xl border border-slate-200 bg-slate-50 p-5">
                            <p class="text-sm font-semibold">Checkout preview</p>
                            <p class="mt-1 text-sm text-slate-500">Enabled badges appear in saved order, with up to three columns per row.</p>
                            <div v-if="enabledTrustItems.length" class="mt-6 grid grid-cols-3 gap-3">
                                <div v-for="item in enabledTrustItems" :key="item.key" class="min-w-0 text-center">
                                    <span class="mx-auto flex size-10 items-center justify-center text-xl font-semibold text-slate-700" aria-hidden="true">{{ iconGlyph(item.icon) }}</span>
                                    <p class="mt-2 break-words text-sm font-semibold text-slate-900">{{ item.title }}</p>
                                    <p v-if="item.description" class="mt-1 break-words text-sm leading-5 text-slate-500">{{ item.description }}</p>
                                </div>
                            </div>
                            <p v-else class="mt-6 text-sm text-slate-500">Enable a badge and add a heading to preview it.</p>
                            <p class="mt-6 border-t border-slate-200 pt-4 text-sm leading-6 text-slate-500">These are Shopify Checkout component icons, not uploaded image files. The exact rendering follows the buyer's Checkout theme and Shopify's extension components.</p>
                        </aside>
                    </div>
                </div>
            </section>
        </div>

        <div v-if="editorOpen" class="fixed inset-0 z-[100] bg-slate-50" role="dialog" aria-modal="true" aria-label="Create or edit strategy">
            <header class="sticky top-0 z-20 flex min-h-16 items-center justify-between border-b border-slate-200 bg-white px-4 shadow-sm sm:px-6"><div class="min-w-0"><p class="truncate font-semibold text-slate-950">{{ editorDraft?.name || 'Strategy' }}</p><p class="text-xs" :class="saveState === 'failed' ? 'text-rose-700' : 'text-slate-500'"><template v-if="saveState === 'saving'">Saving…</template><template v-else-if="saveState === 'saved'">Saved {{ formatDate(savedAt) }}</template><template v-else-if="saveState === 'failed'">Save failed. Try again.</template><template v-else>Waiting to autosave</template></p></div><div class="flex items-center gap-2"><button v-if="saveState === 'failed'" type="button" class="rounded-lg border border-rose-200 px-3 py-2 text-sm font-semibold text-rose-700" @click="saveDraft">Retry save</button><button type="button" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white" @click="requestClose">Close</button></div></header>
            <main class="h-[calc(100vh-4rem)] overflow-y-auto p-4 sm:p-6 lg:p-8"><div v-if="editorLoading" class="mx-auto max-w-5xl rounded-2xl bg-white p-12 text-center text-slate-500">Loading strategy…</div><div v-else-if="editorDraft" class="mx-auto max-w-5xl space-y-6 pb-20">
                <p v-if="editorError" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">{{ editorError }}</p>
                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-xl font-semibold">Strategy name</h2><label class="mt-5 block text-sm font-medium">Name<input v-model="editorDraft.name" maxlength="80" class="mt-1 w-full rounded-xl border-slate-300" placeholder="For example: Product page accessories"></label></section>
                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-xl font-semibold">Recommendation rules</h2>
                    <p class="mt-1 text-sm text-slate-500">Start with a preset or create custom rules for specific targets.</p>

                    <div class="mt-5 rounded-xl border border-slate-200 p-4">
                        <label class="flex cursor-pointer items-start gap-3">
                            <input :checked="editorDraft.configuration.recommendation_rule.mode === 'preset'" type="radio" name="recommendation-mode" class="mt-1" @change="setRecommendationMode('preset')">
                            <span><strong class="text-sm">Preset rule</strong><span class="mt-1 block text-xs text-slate-500">Use a proven deterministic recommendation method.</span></span>
                        </label>
                        <div v-if="editorDraft.configuration.recommendation_rule.mode === 'preset'" class="ml-7 mt-4 space-y-4">
                            <label class="block text-sm font-medium">Rule
                                <select v-model="editorDraft.configuration.recommendation_rule.preset" class="mt-1 w-full max-w-xl rounded-xl border-slate-300">
                                    <option v-for="option in options.algorithms" :key="option.value" :value="option.value">{{ option.label }}</option>
                                </select>
                            </label>
                            <template v-if="editorDraft.configuration.recommendation_rule.preset === 'manual'">
                                <p class="text-sm text-slate-500">Add up to 24 products and set a minimum purchase quantity for each.</p>
                                <div class="flex items-center justify-between gap-4">
                                    <h3 class="text-sm font-semibold">Products ({{ editorDraft.configuration.products.manual.length }})</h3>
                                    <button type="button" class="text-sm font-semibold text-indigo-700 hover:text-indigo-900" @click="openProductPicker('manual')">Select</button>
                                </div>
                                <div v-if="manualError" class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800" role="alert">{{ manualError }}</div>
                                <div v-if="hasUnavailableManualProducts" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800" role="status">Some products are sold out, archived, or unavailable and will be skipped automatically on the storefront.</div>
                                <p v-if="!editorDraft.configuration.products.manual.length && !manualError" class="text-sm text-slate-500">No products selected.</p>
                                <div v-else-if="editorDraft.configuration.products.manual.length" class="divide-y divide-slate-100 rounded-xl border border-slate-200">
                                    <div v-for="(item, index) in editorDraft.configuration.products.manual" :key="item.shopify_product_id" draggable="true" class="grid gap-3 p-3 sm:grid-cols-[32px_48px_1fr_150px_auto] sm:items-center" @dragstart="startProductDrag(index)" @dragover.prevent @drop="dropProductSelection(editorDraft.configuration.products.manual, index)">
                                        <button type="button" class="cursor-grab text-lg text-slate-400" aria-label="Drag to reorder">⋮⋮</button>
                                        <img v-if="product(item.shopify_product_id)?.image_url" :src="product(item.shopify_product_id)?.image_url!" class="size-12 rounded-lg object-cover" alt="">
                                        <div><p class="font-medium">{{ product(item.shopify_product_id)?.title ?? 'Removed or unsynced product' }}</p><span class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="product(item.shopify_product_id)?.available_for_sale ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'">{{ product(item.shopify_product_id)?.availability_label ?? 'Unavailable' }}</span></div>
                                        <label class="text-xs text-slate-500">Minimum purchase quantity<input v-model.number="item.minimum_quantity" type="number" min="1" max="999" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label>
                                        <div class="flex items-center justify-end gap-2"><button type="button" class="text-xs text-slate-500 disabled:opacity-30" :disabled="index === 0" @click="moveProductSelection(editorDraft.configuration.products.manual, index, -1)">Move up</button><button type="button" class="text-xs text-slate-500 disabled:opacity-30" :disabled="index === editorDraft.configuration.products.manual.length - 1" @click="moveProductSelection(editorDraft.configuration.products.manual, index, 1)">Move down</button><button type="button" class="text-sm font-semibold text-rose-700" @click="removeProduct('manual', item.shopify_product_id)">Delete</button></div>
                                    </div>
                                </div>
                            </template>
                            <p v-else class="rounded-xl bg-slate-50 p-3 text-sm text-slate-600">Commerce Hub calculates this preset from deterministic product, inventory, and order data.</p>
                        </div>
                    </div>

                    <div class="mt-4 rounded-xl border border-slate-200 p-4">
                        <label class="flex cursor-pointer items-start gap-3">
                            <input :checked="editorDraft.configuration.recommendation_rule.mode === 'custom'" type="radio" name="recommendation-mode" class="mt-1" @change="setRecommendationMode('custom')">
                            <span><strong class="text-sm">Custom rules ({{ customRules.length }})</strong><span class="mt-1 block text-xs text-slate-500">Combine cart product, collection, tag, and vendor conditions by priority.</span></span>
                        </label>
                        <div v-if="editorDraft.configuration.recommendation_rule.mode === 'custom'" class="ml-7 mt-4">
                            <button type="button" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold" @click="openCustomRuleEditor">EditRule</button>
                            <div v-if="customRuleError" class="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800" role="alert">{{ customRuleError }}</div>
                        </div>
                    </div>
                </section>
                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><div class="flex items-start justify-between gap-4"><div><h2 class="text-xl font-semibold">Pinned products</h2><p class="mt-1 text-sm text-slate-500">Pinned products appear before regular products and are safely skipped when out of stock or unavailable.</p></div><button type="button" class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold" @click="openProductPicker('pinned')">Select</button></div><div v-if="editorDraft.configuration.products.pinned.length" class="mt-4 flex flex-wrap gap-2"><span v-for="item in editorDraft.configuration.products.pinned" :key="item.shopify_product_id" class="inline-flex items-center gap-2 rounded-xl bg-indigo-50 px-3 py-2 text-sm text-indigo-900">{{ product(item.shopify_product_id)?.title ?? item.shopify_product_id }}<button type="button" class="font-bold text-rose-600" @click="removeProduct('pinned', item.shopify_product_id)">×</button></span></div><p v-else class="mt-4 text-sm text-slate-500">No pinned products.</p></section>
                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-xl font-semibold">Exclusions</h2><div class="mt-5 space-y-4"><label class="flex items-start gap-3 rounded-xl bg-slate-50 p-4 text-sm"><input v-model="excludeHistory" type="checkbox" class="mt-0.5 rounded"><span><strong>Exclude products from the cart or order</strong><span class="mt-1 block text-xs text-slate-500">Enabled by default to avoid recommending products already present。</span></span></label><div class="rounded-xl border border-slate-200 p-4"><label class="flex gap-3 text-sm font-semibold"><input v-model="excludeSpecificEnabled" type="checkbox" class="rounded">Exclude specific products</label><div v-if="excludeSpecificEnabled" class="mt-3"><button type="button" class="rounded-lg border border-slate-300 px-3 py-2 text-sm" @click="openProductPicker('excluded')">Select products ({{ editorDraft.configuration.products.excluded.length }})</button></div></div><div class="grid gap-4 md:grid-cols-2"><label class="rounded-xl border border-slate-200 p-4 text-sm"><span class="flex gap-3 font-semibold"><input v-model="excludeTagsEnabled" type="checkbox" class="rounded">Exclude by tag</span><select v-if="excludeTagsEnabled" v-model="editorDraft.configuration.rules.exclude_tags" multiple class="mt-3 h-28 w-full rounded-lg border-slate-300"><option v-for="tag in availableTags" :key="tag" :value="tag">{{ tag }}</option></select></label><label class="rounded-xl border border-slate-200 p-4 text-sm"><span class="flex gap-3 font-semibold"><input v-model="excludeCollectionsEnabled" type="checkbox" class="rounded">Exclude by collection</span><select v-if="excludeCollectionsEnabled" v-model="editorDraft.configuration.rules.exclude_collection_ids" multiple class="mt-3 h-28 w-full rounded-lg border-slate-300"><option v-for="collection in collections" :key="collection.shopify_collection_id" :value="collection.shopify_collection_id">{{ collection.title }}</option></select></label><label class="rounded-xl border border-slate-200 p-4 text-sm"><span class="flex gap-3 font-semibold"><input v-model="excludeVendorsEnabled" type="checkbox" class="rounded">Exclude by vendor</span><select v-if="excludeVendorsEnabled" v-model="editorDraft.configuration.rules.exclude_vendors" multiple class="mt-3 h-28 w-full rounded-lg border-slate-300"><option v-for="vendor in availableVendors" :key="vendor" :value="vendor">{{ vendor }}</option></select></label><label class="rounded-xl border border-slate-200 p-4 text-sm"><span class="flex gap-3 font-semibold"><input v-model="excludePurchaseOptionsEnabled" type="checkbox" class="rounded">Exclude by purchase option</span><select v-if="excludePurchaseOptionsEnabled" v-model="editorDraft.configuration.rules.exclude_purchase_options" multiple class="mt-3 h-28 w-full rounded-lg border-slate-300"><option v-for="option in purchaseOptions" :key="option" :value="option">{{ option }}</option></select></label></div></div></section>
                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="text-xl font-semibold">Promotions</h2><div class="mt-5 flex gap-3"><label class="flex items-center gap-2 rounded-xl border px-4 py-3 text-sm font-semibold" :class="!editorDraft.configuration.discount.enabled ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-200'"><input v-model="editorDraft.configuration.discount.enabled" :value="false" type="radio">Disabled</label><label class="flex items-center gap-2 rounded-xl border px-4 py-3 text-sm font-semibold" :class="editorDraft.configuration.discount.enabled ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-200'"><input v-model="editorDraft.configuration.discount.enabled" :value="true" type="radio">Enabled</label></div><div v-if="editorDraft.configuration.discount.enabled" class="mt-5 rounded-xl bg-slate-50 p-4"><p v-if="selectedDiscount" class="text-sm"><strong>{{ selectedDiscount.title }}</strong><span class="ml-2 text-slate-500">{{ selectedDiscount.summary }}</span></p><p v-else-if="editorDraft.configuration.discount.reference" class="text-sm text-slate-700">A Shopify discount is linked. Open the list to refresh its current name and status.</p><p v-else class="text-sm text-amber-700">Choose a discount when promotions are enabled。</p><button type="button" class="mt-3 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold" @click="openDiscounts">Select or manage discount</button></div></section>
            </div></main>
            <div v-if="closePrompt" class="absolute inset-0 z-30 flex items-center justify-center bg-slate-950/50 p-4"><div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl" role="alertdialog" aria-modal="true"><h2 class="text-lg font-semibold">Close strategy editor？</h2><p class="mt-2 text-sm leading-6 text-slate-600">Your current changes are retained. If the last autosave failed, continue editing and retry before closing.</p><div class="mt-6 flex justify-end gap-3"><button type="button" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold" @click="closePrompt = false">Keep editing</button><button type="button" class="rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white" @click="closeEditor">Close</button></div></div></div>
        </div>

        <div v-if="customRuleEditorOpen && editorDraft" class="fixed inset-0 z-[115] bg-slate-50" role="dialog" aria-modal="true" aria-label="Custom rule editor">
            <header class="sticky top-0 z-20 flex min-h-16 items-center justify-between border-b border-slate-200 bg-white px-4 shadow-sm sm:px-6"><div><h2 class="font-semibold">Custom rules</h2><p class="text-xs text-slate-500">Rules run from top to bottom by priority and save automatically.</p></div><button type="button" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white" @click="closeCustomRuleEditor">Close</button></header>
            <div class="grid h-[calc(100vh-4rem)] min-h-0 lg:grid-cols-[320px_1fr]">
                <aside class="overflow-y-auto border-b border-slate-200 bg-white p-4 lg:border-b-0 lg:border-r">
                    <div class="flex items-center justify-between"><div><h3 class="font-semibold">Rule set</h3><p class="text-xs text-slate-500">{{ customRules.length }}  rules</p></div><button type="button" class="text-sm font-semibold text-indigo-700 disabled:opacity-40" :disabled="customRules.length >= 20" @click="addCustomRule">Add rule</button></div>
                    <div class="mt-4 space-y-2">
                        <button v-for="(rule, index) in customRules" :key="rule.id" type="button" draggable="true" class="block w-full rounded-xl border p-3 text-left" :class="activeCustomRule?.id === rule.id ? 'border-indigo-400 bg-indigo-50' : 'border-slate-200 bg-white'" @click="activeCustomRuleId = rule.id" @dragstart="startRuleDrag(index)" @dragover.prevent @drop="dropRule(index)">
                            <span class="flex items-center gap-2"><span class="cursor-grab text-slate-400">⋮⋮</span><strong class="min-w-0 flex-1 truncate text-sm">{{ index + 1 }}. {{ rule.name }}</strong></span>
                            <span class="mt-2 block text-xs leading-5 text-slate-500">{{ ruleSummary(rule) }}</span>
                            <span class="mt-2 flex gap-3 text-xs text-slate-500"><span @click.stop="moveCustomRule(index, -1)">Move up</span><span @click.stop="moveCustomRule(index, 1)">Move down</span></span>
                        </button>
                    </div>
                    <button type="button" class="mt-4 w-full rounded-xl border border-dashed border-slate-300 px-4 py-3 text-sm font-semibold text-slate-700 disabled:opacity-40" :disabled="customRules.length >= 20" @click="addCustomRule">+ Add rule</button>
                    <div class="mt-6 rounded-xl border border-slate-200 p-4">
                        <label class="flex items-start gap-3 text-sm font-semibold"><input v-model="editorDraft.configuration.recommendation_rule.custom.fallback.enabled" type="checkbox" class="mt-1 rounded"><span>Add fallback rule<span class="mt-1 block text-xs font-normal leading-5 text-slate-500">When regular rules return too few eligible candidates, add fallback products in order.</span></span></label>
                    </div>
                </aside>

                <main class="overflow-y-auto p-4 sm:p-6 lg:p-8">
                    <div v-if="activeCustomRule" class="mx-auto max-w-5xl space-y-6 pb-12">
                        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><label class="block flex-1 text-sm font-medium">Rule name (50 characters maximum)<input v-model="activeCustomRule.name" maxlength="50" class="mt-1 w-full rounded-xl border-slate-300"></label><button type="button" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-700" @click="askDeleteCustomRule(activeCustomRule)">DeleteRule</button></div>
                        </section>

                        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div><h3 class="text-lg font-semibold">Conditions / If</h3><p class="mt-1 text-sm text-slate-500">If the required context is missing, this rule is treated as not matched.</p></div><label v-if="activeCustomRule.conditions.length > 1" class="text-sm">Condition logic<select v-model="activeCustomRule.match" class="ml-2 rounded-lg border-slate-300 text-sm"><option value="all">AND (all conditions)</option><option value="any">OR (any condition)</option></select></label></div>
                            <div class="mt-5 space-y-4">
                                <div v-for="condition in activeCustomRule.conditions" :key="condition.id" class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                    <div class="grid gap-3 md:grid-cols-[1fr_1fr_auto]"><label class="text-xs">Condition field<select v-model="condition.field" class="mt-1 w-full rounded-lg border-slate-300 text-sm" @change="condition.values = []"><option value="cart_product_ids">Products in cart</option><option value="cart_collection_ids">Cart product collections</option><option value="cart_tags">Cart product tags</option><option value="cart_vendors">Cart product vendors</option></select></label><label class="text-xs">Operator<select v-model="condition.operator" class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="contains_any">Contains any</option><option value="contains_all">Contains all</option><option value="contains_none">Contains none</option></select></label><button type="button" class="self-end rounded-lg px-3 py-2 text-sm font-semibold text-rose-700" @click="removeCondition(activeCustomRule, condition.id)">Delete</button></div>
                                    <div class="mt-3"><button v-if="condition.field === 'cart_product_ids'" type="button" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold" @click="openProductPicker('custom-condition', activeCustomRule.id, condition.id)">Select products ({{ condition.values.length }})</button><label v-else class="block text-xs">Values<select v-model="condition.values" multiple class="mt-1 h-28 w-full rounded-lg border-slate-300 bg-white text-sm"><option v-for="option in optionValues(condition.field)" :key="option.value" :value="option.value">{{ option.label }}</option></select></label></div>
                                </div>
                            </div>
                            <button type="button" class="mt-4 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold disabled:opacity-40" :disabled="activeCustomRule.conditions.length >= 10" @click="addCondition(activeCustomRule)">+ Add condition</button>
                            <label class="mt-5 flex items-start gap-3 rounded-xl bg-indigo-50 p-4 text-sm"><input v-model="activeCustomRule.exit_on_match" type="checkbox" class="mt-1 rounded"><span><strong>Stop after a match</strong><span class="mt-1 block text-xs text-indigo-800">Stop evaluating lower-priority rules after a match. Otherwise, continue merging results and remove duplicates by first appearance.</span></span></label>
                        </section>

                        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                            <h3 class="text-lg font-semibold">Action / Apply</h3><p class="mt-1 text-sm text-slate-500">Actions use manually selected products in their saved order.</p>
                            <div class="mt-5 flex items-center justify-between"><h4 class="text-sm font-semibold">Recommended products ({{ activeCustomRule.action.products.length }})</h4><button type="button" class="text-sm font-semibold text-indigo-700" @click="openProductPicker('custom-action', activeCustomRule.id)">Select</button></div>
                            <div v-if="activeCustomRule.action.products.length" class="mt-3 divide-y divide-slate-100 rounded-xl border border-slate-200"><div v-for="(item, index) in activeCustomRule.action.products" :key="item.shopify_product_id" draggable="true" class="grid gap-3 p-3 sm:grid-cols-[32px_48px_1fr_140px_auto] sm:items-center" @dragstart="startProductDrag(index)" @dragover.prevent @drop="dropProductSelection(activeCustomRule.action.products, index)"><span class="cursor-grab text-slate-400">⋮⋮</span><img v-if="product(item.shopify_product_id)?.image_url" :src="product(item.shopify_product_id)?.image_url!" class="size-12 rounded-lg object-cover" alt=""><div><p class="font-medium">{{ product(item.shopify_product_id)?.title ?? 'Unavailable product' }}</p><span class="text-xs" :class="product(item.shopify_product_id)?.available_for_sale ? 'text-emerald-700' : 'text-amber-700'">{{ product(item.shopify_product_id)?.availability_label ?? 'Configuration error' }}</span></div><label class="text-xs">Minimum purchase quantity<input v-model.number="item.minimum_quantity" type="number" min="1" max="999" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label><div class="flex gap-2"><button type="button" class="text-xs text-slate-500" @click="moveProductSelection(activeCustomRule.action.products, index, -1)">Move up</button><button type="button" class="text-xs text-slate-500" @click="moveProductSelection(activeCustomRule.action.products, index, 1)">Move down</button><button type="button" class="text-sm font-semibold text-rose-700" @click="removeActionProduct(activeCustomRule.action, item.shopify_product_id)">Delete</button></div></div></div>
                            <div class="mt-6"><div class="flex items-center justify-between"><h4 class="text-sm font-semibold">Filters ({{ activeCustomRule.action.filters.length }})</h4><button type="button" class="text-sm font-semibold text-indigo-700" @click="addActionFilter(activeCustomRule.action)">Add filter</button></div><div class="mt-3 space-y-3"><div v-for="filter in activeCustomRule.action.filters" :key="filter.id" class="grid gap-3 rounded-xl bg-slate-50 p-4 md:grid-cols-[1fr_1fr_1.4fr_auto]"><select v-model="filter.field" class="rounded-lg border-slate-300 text-sm" @change="filter.values = []"><option value="product_tags">Product tags</option><option value="product_collections">Product collections</option><option value="product_vendors">Product vendors</option></select><select v-model="filter.operator" class="rounded-lg border-slate-300 text-sm"><option value="contains_any">Contains any</option><option value="contains_all">Contains all</option><option value="contains_none">Contains none</option></select><select v-model="filter.values" multiple class="h-24 rounded-lg border-slate-300 text-sm"><option v-for="option in optionValues(filter.field)" :key="option.value" :value="option.value">{{ option.label }}</option></select><button type="button" class="text-sm font-semibold text-rose-700" @click="removeActionFilter(activeCustomRule.action, filter.id)">Delete</button></div></div></div>
                        </section>

                        <section v-if="editorDraft.configuration.recommendation_rule.custom.fallback.enabled" class="rounded-2xl border border-indigo-200 bg-indigo-50 p-6 shadow-sm">
                            <h3 class="text-lg font-semibold">Fallback rule</h3><p class="mt-1 text-sm text-indigo-800">Use fallback products when regular rules do not return enough eligible candidates. Returned, excluded, or unavailable products stay excluded.</p>
                            <div class="mt-5 flex items-center justify-between"><h4 class="text-sm font-semibold">Fallback products ({{ editorDraft.configuration.recommendation_rule.custom.fallback.action.products.length }})</h4><button type="button" class="text-sm font-semibold text-indigo-800" @click="openProductPicker('fallback-action')">Select</button></div>
                            <div v-if="editorDraft.configuration.recommendation_rule.custom.fallback.action.products.length" class="mt-3 divide-y divide-indigo-100 rounded-xl border border-indigo-200 bg-white"><div v-for="(item, index) in editorDraft.configuration.recommendation_rule.custom.fallback.action.products" :key="item.shopify_product_id" class="grid gap-3 p-3 sm:grid-cols-[48px_1fr_140px_auto] sm:items-center"><img v-if="product(item.shopify_product_id)?.image_url" :src="product(item.shopify_product_id)?.image_url!" class="size-12 rounded-lg object-cover" alt=""><div><p class="font-medium">{{ product(item.shopify_product_id)?.title ?? 'Unavailable product' }}</p><p class="text-xs text-slate-500">{{ product(item.shopify_product_id)?.availability_label ?? 'Configuration error' }}</p></div><label class="text-xs">Minimum purchase quantity<input v-model.number="item.minimum_quantity" type="number" min="1" max="999" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label><button type="button" class="text-sm font-semibold text-rose-700" @click="removeActionProduct(editorDraft.configuration.recommendation_rule.custom.fallback.action, item.shopify_product_id)">Delete</button></div></div>
                            <div class="mt-5 flex items-center justify-between"><h4 class="text-sm font-semibold">Fallback filters ({{ editorDraft.configuration.recommendation_rule.custom.fallback.action.filters.length }})</h4><button type="button" class="text-sm font-semibold text-indigo-800" @click="addActionFilter(editorDraft.configuration.recommendation_rule.custom.fallback.action)">Add filter</button></div>
                            <div class="mt-3 space-y-3"><div v-for="filter in editorDraft.configuration.recommendation_rule.custom.fallback.action.filters" :key="filter.id" class="grid gap-3 rounded-xl border border-indigo-100 bg-white p-4 md:grid-cols-[1fr_1fr_1.4fr_auto]"><select v-model="filter.field" class="rounded-lg border-slate-300 text-sm" @change="filter.values = []"><option value="product_tags">Product tags</option><option value="product_collections">Product collections</option><option value="product_vendors">Product vendors</option></select><select v-model="filter.operator" class="rounded-lg border-slate-300 text-sm"><option value="contains_any">Contains any</option><option value="contains_all">Contains all</option><option value="contains_none">Contains none</option></select><select v-model="filter.values" multiple class="h-24 rounded-lg border-slate-300 text-sm"><option v-for="option in optionValues(filter.field)" :key="option.value" :value="option.value">{{ option.label }}</option></select><button type="button" class="text-sm font-semibold text-rose-700" @click="removeActionFilter(editorDraft.configuration.recommendation_rule.custom.fallback.action, filter.id)">Delete</button></div></div>
                        </section>
                    </div>
                    <div v-else class="mx-auto max-w-xl rounded-2xl bg-white p-12 text-center text-slate-500">Add or select a rule from the left.</div>
                </main>
            </div>
        </div>

        <div v-if="customRuleDeleteTarget" class="fixed inset-0 z-[145] flex items-center justify-center bg-slate-950/55 p-4" role="alertdialog" aria-modal="true" aria-labelledby="delete-custom-rule-title"><div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl"><h2 id="delete-custom-rule-title" class="text-lg font-semibold">Delete rule?</h2><p class="mt-3 text-sm leading-6 text-slate-600">Delete “<strong class="text-slate-950">{{ customRuleDeleteTarget.name }}</strong>”? This action cannot be undone.</p><p v-if="customRuleDeleteError" class="mt-3 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{{ customRuleDeleteError }}</p><div class="mt-6 flex justify-end gap-3"><button type="button" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold" @click="customRuleDeleteTarget = null">Cancel</button><button type="button" class="rounded-xl bg-rose-600 px-5 py-2 text-sm font-semibold text-white" @click="confirmDeleteCustomRule">Delete</button></div></div></div>

        <div v-if="pickerMode" class="fixed inset-0 z-[130] flex items-center justify-center bg-slate-950/55 p-4" role="dialog" aria-modal="true" aria-label="Select products">
            <div class="flex max-h-[88vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
                <header class="border-b border-slate-200 p-5"><div class="flex items-center justify-between"><div><h2 class="text-xl font-semibold">{{ pickerTitle }}</h2><p class="mt-1 text-sm text-slate-500">Select up to 24 products. Selection order is preserved.</p></div><button type="button" class="rounded-lg p-2 text-xl" aria-label="Close" @click="closeProductPicker">×</button></div><input v-model="pickerSearch" type="search" placeholder="Search product name or status" class="mt-4 w-full rounded-xl border-slate-300"></header>
                <div class="min-h-0 flex-1 overflow-y-auto"><p v-if="pickerError" class="m-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{{ pickerError }}</p><div v-for="item in filteredProducts" :key="item.shopify_product_id" class="grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-[auto_52px_1fr_210px] sm:items-center"><input type="checkbox" :checked="isPickerSelected(item.shopify_product_id)" @change="togglePickerProduct(item.shopify_product_id)"><img v-if="item.image_url" :src="item.image_url" class="size-12 rounded-lg object-cover" alt=""><div><p class="font-medium">{{ item.title }}</p><span class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="item.available_for_sale ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'">{{ item.availability_label }}</span></div><div v-if="pickerAllowsQuantity" class="grid gap-2"><label class="text-xs text-slate-500">Variant<select :value="pickerSelections.find(row => row.shopify_product_id === item.shopify_product_id)?.variant_gid ?? ''" :disabled="!isPickerSelected(item.shopify_product_id)" class="mt-1 w-full rounded-lg border-slate-300 text-sm" @change="updatePickerVariant(item.shopify_product_id, ($event.target as HTMLSelectElement).value)"><option v-for="variant in item.variants" :key="variant.shopify_gid" :value="variant.shopify_gid">{{ variant.title }}{{ variant.available_for_sale ? '' : ' (Unavailable)' }}</option></select></label><label class="text-xs text-slate-500">Minimum purchase quantity<input :value="pickerSelections.find(row => row.shopify_product_id === item.shopify_product_id)?.minimum_quantity ?? 1" type="number" min="1" max="999" :disabled="!isPickerSelected(item.shopify_product_id)" class="mt-1 w-full rounded-lg border-slate-300 text-sm" @input="updatePickerQuantity(item.shopify_product_id, ($event.target as HTMLInputElement).value)"></label></div></div></div>
                <footer class="flex items-center justify-between border-t border-slate-200 p-5"><span class="text-sm font-semibold">Selected {{ pickerSelections.length }} products</span><div class="flex gap-3"><button type="button" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold" @click="closeProductPicker">Cancel</button><button type="button" class="rounded-xl bg-slate-950 px-5 py-2 text-sm font-semibold text-white disabled:opacity-40" :disabled="pickerSelections.length === 0" @click="confirmProductPicker">Confirm</button></div></footer>
            </div>
        </div>

        <div v-if="discountModal" class="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/55 p-4" role="dialog" aria-modal="true" aria-label="Select discount"><div class="max-h-[88vh] w-full max-w-3xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl"><div class="flex items-center justify-between"><div><h2 class="text-xl font-semibold">Promotions</h2><p class="mt-1 text-sm text-slate-500">Select an existing discount or create and edit a Shopify basic discount code.</p></div><button type="button" class="text-xl" @click="discountModal = false">×</button></div><p v-if="discountError" class="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{{ discountError }}</p><div class="mt-5 rounded-xl border border-indigo-200 bg-indigo-50 p-4"><h3 class="font-semibold">Default 10% off</h3><p class="mt-1 text-sm text-indigo-800">Create a 10% off code for the currently recommended products.</p></div><div class="mt-5 space-y-2"><div v-for="discount in discounts" :key="discount.id" class="flex items-center justify-between rounded-xl border border-slate-200 p-4"><span><strong>{{ discount.title }}</strong><span class="mt-1 block text-xs text-slate-500">{{ discount.summary }} · {{ discount.code }} · {{ discount.status }}</span></span><span class="flex gap-2"><button v-if="discount.editable" type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold" @click="editDiscount(discount)">Edit</button><button type="button" class="rounded-lg bg-indigo-700 px-3 py-1.5 text-xs font-semibold text-white" @click="chooseDiscount(discount)">Select</button></span></div><p v-if="discountLoading" class="py-5 text-center text-sm text-slate-500">Loading Shopify discounts…</p></div><form class="mt-6 rounded-xl bg-slate-50 p-4" @submit.prevent="saveDiscount"><h3 class="font-semibold">{{ discountForm.id ? 'Edit discount' : 'Add discount' }}</h3><div class="mt-3 grid gap-3 sm:grid-cols-3"><label class="text-xs">Name<input v-model="discountForm.title" required maxlength="80" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label><label class="text-xs">Discount code<input v-model="discountForm.code" required maxlength="40" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label><label class="text-xs">Discount percentage<input v-model.number="discountForm.percentage" required type="number" min="1" max="99" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></label></div><button class="mt-4 rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white" :disabled="discountLoading">{{ discountLoading ? 'Saving…' : 'Save and select' }}</button></form></div></div>

        <div v-if="deleteTarget" class="fixed inset-0 z-[130] flex items-center justify-center bg-slate-950/55 p-4" role="alertdialog" aria-modal="true" aria-labelledby="delete-strategy-title" aria-describedby="delete-strategy-description" @mousedown.self="closeDelete"><div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl"><h2 id="delete-strategy-title" class="text-lg font-semibold">Delete strategy?</h2><div id="delete-strategy-description" class="mt-3 space-y-2 text-sm leading-6 text-slate-600"><p>Delete “<strong class="text-slate-950">{{ deleteTarget.name }}</strong>”? This action cannot be undone.</p><p v-if="deleteTarget.used_in.length" class="rounded-xl bg-amber-50 p-3 text-amber-800">Assigned placements will stop recommending after deletion, and components will hide when no results are available.</p></div><p v-if="deleteError" class="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{{ deleteError }}</p><div class="mt-6 flex justify-end gap-3"><button type="button" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold" :disabled="deleteProcessing" @click="closeDelete">Cancel</button><button type="button" class="rounded-xl bg-rose-600 px-5 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="deleteProcessing" @click="confirmDelete">{{ deleteProcessing ? 'Deleting…' : 'Delete' }}</button></div></div></div>
    </AppLayout>
</template>
