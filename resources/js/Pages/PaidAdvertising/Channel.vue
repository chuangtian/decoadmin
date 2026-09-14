<script setup lang="ts">
import vReadableChart from '../../directives/readableChart';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import GoogleAdsGoalTemplate from '../../Components/PaidAdvertising/GoogleAdsGoalTemplate.vue';
import GoogleAdsWeeklyReport from '../../Components/PaidAdvertising/GoogleAdsWeeklyReport.vue';
import { useToast } from '../../composables/useToast';
import GooglePerformanceTable from './GooglePerformanceTable.vue';
import type { SharedProps } from '../../types';

type ChannelState = 'not_configured' | 'pending' | 'syncing' | 'backfilling' | 'completed' | 'failed' | 'partial_failed';
type ChannelStatus = {
    schema: 'advertising-channel-sync-status-v1'; channel: string; label: string; configured: boolean;
    settings_url: string; state: ChannelState; mode: string | null; data_ready: boolean;
    progress_percent: number; completed_chunks: number; total_chunks: number; last_metric_date: string | null;
    data_synced_at: string | null; last_success_at: string | null; message: string | null;
};
type AdvertisingSyncState = 'started' | 'progress' | 'completed' | 'failed';
type AdvertisingSyncSource = 'google_ads' | 'feishu_google_ads';
type AdvertisingSyncStatus = {
    channel: string;
    state: AdvertisingSyncState;
    mode?: string;
    source?: AdvertisingSyncSource;
    views?: string[];
    progress_percent?: number;
    last_metric_date?: string | null;
    data_synced_at?: string | null;
    message?: string | null;
};
type GoogleSummary = {
    spend: number; revenue: number; roas: number; cpa: number; add_to_cart: number; checkout: number;
    add_to_cart_cost: number; checkout_cost: number;
    impressions: number; clicks: number; conversions: number; ctr: number; cpc: number;
};
type GoogleTrendPoint = {
    date: string; spend: number; revenue: number; conversion_value: number; roas: number; roi: number;
    impressions: number; clicks: number; ctr: number; cpc: number; conversions: number;
};
type GoogleCampaignSpend = { id: string; name: string; spend: number; percentage: number };
type GoogleCampaign = {
    id: string; name: string; status: string; channel_type: string; spend: number; revenue: number;
    roas: number; impressions: number; clicks: number; ctr: number; cpc: number; conversions: number;
};
type GoogleDailySortKey = keyof Pick<GoogleTrendPoint, 'date' | 'spend' | 'revenue' | 'conversion_value' | 'roas' | 'roi' | 'impressions' | 'clicks' | 'ctr' | 'cpc' | 'conversions'>;
type GoogleCampaignSortKey = keyof Pick<GoogleCampaign, 'name' | 'channel_type' | 'spend' | 'revenue' | 'roas' | 'impressions' | 'clicks' | 'ctr' | 'cpc' | 'conversions' | 'status'>;
type GoogleOverview = {
    schema: 'google-ads-overview-v2';
    accounts: Array<{ id: string; name: string; currency: string; timezone: string | null }>;
    filters: { account: string | null; date_from: string; date_to: string };
    comparison_period: { date_from: string; date_to: string };
    currency: string;
    current: GoogleSummary;
    previous: GoogleSummary;
    trend: GoogleTrendPoint[];
    campaign_spend: GoogleCampaignSpend[];
    campaigns: GoogleCampaign[];
    funnel: Array<{ key: 'impressions' | 'clicks' | 'conversions'; label: string; value: number }>;
    deltas: Record<keyof GoogleSummary, number | null>;
};
type PerformanceView = 'search-terms' | 'keywords';
type GooglePerformanceTablePayload = {
    schema: 'google-ads-performance-table-v1';
    view: PerformanceView;
    filters: { account: string | null; date_from: string; date_to: string; search: string; sort: string; direction: 'asc' | 'desc' };
    rows: Array<Record<string, string | number | null>>;
    pagination: { page: number; per_page: number; total: number; last_page: number };
};
type WeeklyMetricKey = 'spend' | 'revenue' | 'roi' | 'conversions' | 'add_to_cart' | 'checkout' | 'add_to_cart_cost' | 'checkout_cost' | 'cpa';
type GoogleWeeklyReport = {
    schema: 'google-ads-weekly-report-v1';
    available: boolean;
    source_table: string | null;
    source_synced_at: string | null;
    weeks: Array<{ key: string; label: string; date_from: string; date_to: string }>;
    selected_week: { key: string; label: string; date_from: string; date_to: string } | null;
    previous_week: { key: string; label: string; date_from: string; date_to: string } | null;
    values: Record<WeeklyMetricKey, number> | null;
    previous_values: Record<WeeklyMetricKey, number> | null;
    changes: Record<WeeklyMetricKey, number | null> | null;
    history: Array<{
        key: string; label: string; date_from: string; date_to: string;
        values: Record<WeeklyMetricKey, number>;
    }>;
    message: string | null;
};
type GoogleGoalFeishuStatus = {
    schema: 'feishu-data-link-status-v1';
    section: 'advertising_goals';
    configured: boolean;
    has_configuration: boolean;
    missing_fields: string[];
};
type GoogleGoalSummary = {
    schema: 'paid-advertising-google-ads-summary-v1';
    available: boolean;
    source: 'database_sync';
    source_sheet: string | null;
    currency: string;
    as_of_date: string | null;
    synced_at: string | null;
    missing_fields: string[];
    message: string | null;
    pace_status: 'ahead' | 'behind';
    values: {
        daily_sales: number | null; monthly_sales: number | null; monthly_target: number | null;
        daily_needed: number | null; daily_achievement_rate: number | null; completion_rate: number | null;
        time_variance: number | null; time_progress: number | null;
    };
    source_fields: Record<string, string | null>;
    efficiency: {
        schema: 'paid-advertising-google-efficiency-v1'; available: boolean; message: string | null;
        values: { current_roas: number | null; target_roas: number | null; achievement_rate: number | null; monthly_spend: number | null };
        source_fields: Record<string, string | null>;
    };
    details: {
        schema: 'paid-advertising-google-target-details-v1'; period_label: string | null;
        columns: Array<{ key: string; label: string; kind: 'date' | 'currency' | 'percentage' | 'roas' | 'text' }>;
        rows: Array<{ key: string; date: string; values: Record<string, string | number | null> }>;
        total: number;
    };
};

const props = defineProps<{
    store: { id: number; name: string };
    channelStatus: ChannelStatus;
    googleOverview: GoogleOverview | null;
    canSync: boolean;
    googleGoalFeishu: GoogleGoalFeishuStatus | null;
    googleGoalSummary: GoogleGoalSummary | null;
    canManageGoogleGoalFeishu: boolean;
}>();
const page = usePage<SharedProps>();
const status = ref(props.channelStatus);
const overview = ref(props.googleOverview);
const filters = ref({
    account: props.googleOverview?.filters.account ?? '',
    date_from: props.googleOverview?.filters.date_from ?? '',
    date_to: props.googleOverview?.filters.date_to ?? '',
});
const activeTab = ref('overview');
const loading = ref(false);
const checking = ref(false);
const manualSyncing = ref(false);
const manualSyncPending = ref(false);
const manualSyncStarted = ref(false);
const manualSyncRequestedAt = ref(0);
const hoveredTrendIndex = ref<number | null>(null);
const hoveredFunnelIndex = ref<number | null>(null);
const hoveredCtrIndex = ref<number | null>(null);
const hoveredCampaignIndex = ref<number | null>(null);
const dailySortKey = ref<GoogleDailySortKey>('date');
const dailySortDirection = ref<'asc' | 'desc'>('desc');
const campaignSearch = ref('');
const campaignSortKey = ref<GoogleCampaignSortKey>('spend');
const campaignSortDirection = ref<'asc' | 'desc'>('desc');
const performanceTable = ref<GooglePerformanceTablePayload | null>(null);
const performanceLoading = ref(false);
const performanceSearch = ref('');
const performanceSort = ref('roas');
const performanceDirection = ref<'asc' | 'desc'>('desc');
const weeklyReport = ref<GoogleWeeklyReport | null>(null);
const weeklyLoading = ref(false);
const googleGoalFeishu = ref(props.googleGoalFeishu);
const googleGoalSummary = ref(props.googleGoalSummary);
const googleGoalAppToken = ref('');
const googleGoalSaving = ref(false);
const googleGoalClearing = ref(false);
const feishuViewsDirty = ref(false);
const toast = useToast();
const organizationId = computed(() => page.props.currentOrganization?.id ?? null);
const syncChannel = computed(() => (organizationId.value ? `advertising-sync.${organizationId.value}.${props.store.id}` : null));
let poller: ReturnType<typeof setInterval> | null = null;
let heartbeatPoller: ReturnType<typeof setInterval> | null = null;
let performanceSearchTimer: ReturnType<typeof setTimeout> | null = null;
let performanceRequestId = 0;
let weeklyRequestId = 0;
let syncEcho: Echo<'reverb'> | null = null;

const isGoogle = computed(() => status.value.channel === 'google');
const polling = computed(() => ['pending', 'syncing', 'backfilling'].includes(status.value.state));
const syncButtonBusy = computed(() => manualSyncing.value || manualSyncPending.value || status.value.state === 'syncing');
const progressWidth = computed(() => `${Math.max(2, Math.min(100, status.value.progress_percent || 0))}%`);
const freshness = computed(() => status.value.last_metric_date ? `数据截止 ${status.value.last_metric_date}` : '等待首批数据');
const selectedAccount = computed(() => overview.value?.accounts.find((account) => account.id === filters.value.account));
const tabs = [
    { key: 'overview', label: '总览', icon: '▥' },
    { key: 'trend', label: '趋势分析', icon: '↗' },
    { key: 'campaigns', label: '广告系列', icon: '▤' },
    { key: 'search-terms', label: '搜索词', icon: '⌕' },
    { key: 'keywords', label: '关键词', icon: '◆' },
    { key: 'weekly', label: '周报', icon: '17' },
    { key: 'goals', label: '目标', icon: '◎' },
];
const cardDefinitions: Array<{ key: keyof GoogleSummary; label: string; format: 'money' | 'number' | 'ratio'; color: string }> = [
    { key: 'spend', label: '广告花费', format: 'money', color: '#3b82f6' },
    { key: 'revenue', label: '广告收入', format: 'money', color: '#10b981' },
    { key: 'roas', label: 'ROAS', format: 'ratio', color: '#14b8a6' },
    { key: 'cpa', label: 'CPA', format: 'money', color: '#f97316' },
    { key: 'add_to_cart', label: '加购数', format: 'number', color: '#8b5cf6' },
    { key: 'checkout', label: '结账数', format: 'number', color: '#22c55e' },
    { key: 'add_to_cart_cost', label: '加购成本', format: 'money', color: '#a855f7' },
    { key: 'checkout_cost', label: '结账成本', format: 'money', color: '#f59e0b' },
];
const secondaryCardDefinitions: Array<{ key: 'impressions' | 'ctr' | 'cpc'; label: string; format: 'money' | 'number' | 'percent'; color: string }> = [
    { key: 'impressions', label: '曝光量', format: 'number', color: '#8b5cf6' },
    { key: 'ctr', label: '平均 CTR', format: 'percent', color: '#f59e0b' },
    { key: 'cpc', label: '平均 CPC', format: 'money', color: '#f97316' },
];
const dailyColumns: Array<{ key: GoogleDailySortKey; label: string; format: 'date' | 'money' | 'ratio' | 'percent' | 'number' }> = [
    { key: 'date', label: '日期', format: 'date' },
    { key: 'spend', label: '花费', format: 'money' },
    { key: 'revenue', label: '收入', format: 'money' },
    { key: 'roas', label: 'ROAS', format: 'ratio' },
    { key: 'conversion_value', label: 'Conv. value', format: 'money' },
    { key: 'roi', label: 'ROI', format: 'ratio' },
    { key: 'impressions', label: '曝光', format: 'number' },
    { key: 'clicks', label: '点击', format: 'number' },
    { key: 'ctr', label: 'CTR', format: 'percent' },
    { key: 'cpc', label: 'CPC', format: 'money' },
    { key: 'conversions', label: '转化', format: 'number' },
];
const campaignColumns: Array<{ key: GoogleCampaignSortKey; label: string; format: 'text' | 'money' | 'ratio' | 'percent' | 'number' }> = [
    { key: 'name', label: '广告系列', format: 'text' },
    { key: 'channel_type', label: '类型', format: 'text' },
    { key: 'spend', label: '花费', format: 'money' },
    { key: 'revenue', label: '收入', format: 'money' },
    { key: 'roas', label: 'ROAS', format: 'ratio' },
    { key: 'impressions', label: '曝光', format: 'number' },
    { key: 'clicks', label: '点击', format: 'number' },
    { key: 'ctr', label: 'CTR', format: 'percent' },
    { key: 'cpc', label: 'CPC', format: 'money' },
    { key: 'conversions', label: '转化', format: 'number' },
    { key: 'status', label: '状态', format: 'text' },
];

const trendChart = computed(() => {
    const width = 760;
    const height = 330;
    const left = 128;
    const right = 112;
    const top = 72;
    const bottom = 52;
    const plotWidth = width - left - right;
    const plotHeight = height - top - bottom;
    const data = overview.value?.trend ?? [];
    const amountMax = Math.max(1, ...data.flatMap((point) => [point.spend, point.revenue])) * 1.12;
    const roiMax = Math.max(1, ...data.map((point) => point.roi)) * 1.15;
    const step = data.length ? plotWidth / data.length : plotWidth;
    const barWidth = Math.max(1.2, Math.min(18, step * 0.22));
    const labelEvery = Math.max(1, Math.ceil(data.length / 7));
    const points = data.map((point, index) => {
        const x = left + step * (index + 0.5);
        return {
            ...point,
            index,
            x,
            spendY: top + plotHeight - (point.spend / amountMax) * plotHeight,
            revenueY: top + plotHeight - (point.revenue / amountMax) * plotHeight,
            roiY: top + plotHeight - (point.roi / roiMax) * plotHeight,
            dateLabel: point.date.slice(5),
            showLabel: data.length <= 10 || index % labelEvery === 0 || index === data.length - 1,
        };
    });
    return {
        width, height, left, right, top, bottom, plotWidth, plotHeight, amountMax, roiMax, step, barWidth, points,
        linePoints: points.map((point) => `${point.x},${point.roiY}`).join(' '),
        ticks: [0, 1, 2, 3, 4].map((index) => ({
            y: top + plotHeight - (index / 4) * plotHeight,
            amount: amountMax * (index / 4),
            roi: roiMax * (index / 4),
        })),
    };
});
const activeTrendPoint = computed(() => hoveredTrendIndex.value === null ? null : trendChart.value.points[hoveredTrendIndex.value] ?? null);
const ctrChart = computed(() => {
    const width = 680;
    const height = 300;
    const left = 104;
    const right = 40;
    const top = 36;
    const bottom = 44;
    const plotWidth = width - left - right;
    const plotHeight = height - top - bottom;
    const data = overview.value?.trend ?? [];
    const max = Math.max(1, ...data.map((point) => point.ctr)) * 1.12;
    const step = data.length > 1 ? plotWidth / (data.length - 1) : plotWidth;
    const hitWidth = Math.max(24, plotWidth / Math.max(1, data.length));
    const labelEvery = Math.max(1, Math.ceil(data.length / 7));
    const points = data.map((point, index) => {
        const x = data.length > 1 ? left + step * index : left + plotWidth / 2;
        return {
            ...point,
            index,
            x,
            hitX: x - hitWidth / 2,
            hitWidth,
            y: top + plotHeight - (point.ctr / max) * plotHeight,
            showLabel: data.length <= 10 || index % labelEvery === 0 || index === data.length - 1,
        };
    });
    const linePoints = points.map((point) => `${point.x},${point.y}`).join(' ');
    const lastPoint = points[points.length - 1];
    const areaPoints = points.length ? `${left},${top + plotHeight} ${linePoints} ${lastPoint?.x ?? left},${top + plotHeight}` : '';

    return {
        width, height, left, right, top, bottom, plotWidth, plotHeight, max, points, linePoints, areaPoints,
        ticks: [0, 1, 2, 3, 4].map((index) => ({
            y: top + plotHeight - (index / 4) * plotHeight,
            value: max * (index / 4),
        })),
    };
});
const activeCtrPoint = computed(() => hoveredCtrIndex.value === null ? null : ctrChart.value.points[hoveredCtrIndex.value] ?? null);

function polarPoint(center: number, radius: number, angle: number): { x: number; y: number } {
    return { x: center + radius * Math.cos(angle), y: center + radius * Math.sin(angle) };
}

const campaignPie = computed(() => {
    const data = overview.value?.campaign_spend ?? [];
    const center = 160;
    const radius = 118;
    let cursor = -Math.PI / 2;
    const colors = ['#3b82f6', '#ef4444', '#f59e0b', '#22c55e', '#8b5cf6', '#f97316'];
    const slices = data.map((campaign, index) => {
        const angle = (campaign.percentage / 100) * Math.PI * 2;
        const start = cursor;
        const end = cursor + angle;
        const startPoint = polarPoint(center, radius, start);
        const endPoint = polarPoint(center, radius, end);
        const labelPoint = polarPoint(center, radius * 0.62, start + angle / 2);
        cursor = end;

        return {
            ...campaign,
            index,
            color: colors[index % colors.length],
            labelX: labelPoint.x,
            labelY: labelPoint.y,
            path: data.length === 1
                ? ''
                : `M ${center} ${center} L ${startPoint.x} ${startPoint.y} A ${radius} ${radius} 0 ${angle > Math.PI ? 1 : 0} 1 ${endPoint.x} ${endPoint.y} Z`,
        };
    });

    return { center, radius, slices };
});
const activeCampaign = computed(() => hoveredCampaignIndex.value === null ? null : campaignPie.value.slices[hoveredCampaignIndex.value] ?? null);
const topCampaigns = computed(() => (overview.value?.campaigns ?? []).slice(0, 6));
const campaignRoasMax = computed(() => Math.max(1, ...topCampaigns.value.map((campaign) => campaign.roas)));
const filteredCampaigns = computed(() => {
    const query = campaignSearch.value.trim().toLocaleLowerCase();
    const rows = (overview.value?.campaigns ?? []).filter((campaign) => !query
        || campaign.name.toLocaleLowerCase().includes(query)
        || campaign.channel_type.toLocaleLowerCase().includes(query));

    return [...rows].sort((left, right) => {
        const first = left[campaignSortKey.value];
        const second = right[campaignSortKey.value];
        const result = typeof first === 'string'
            ? first.localeCompare(String(second), 'zh-CN')
            : Number(first) - Number(second);
        return campaignSortDirection.value === 'asc' ? result : -result;
    });
});
const sortedDailyRows = computed(() => [...(overview.value?.trend ?? [])].sort((left, right) => {
    const first = left[dailySortKey.value];
    const second = right[dailySortKey.value];
    const result = typeof first === 'string' ? first.localeCompare(String(second)) : Number(first) - Number(second);
    return dailySortDirection.value === 'asc' ? result : -result;
}));
const funnelChart = computed(() => {
    const stages = overview.value?.funnel ?? [];
    const maxValue = Math.max(1, stages[0]?.value ?? 0);
    const center = 280;
    const widths = stages.map((stage) => Math.max(28, 500 * Math.sqrt(stage.value / maxValue)));

    return stages.map((stage, index) => {
        const topWidth = widths[index] ?? 28;
        const bottomWidth = widths[index + 1] ?? Math.max(20, topWidth * 0.55);
        const y = 28 + index * 82;
        return {
            ...stage,
            index,
            color: ['#4f86ed', '#f6bf3d', '#56a65c'][index] ?? '#94a3b8',
            points: [
                `${center - topWidth / 2},${y}`,
                `${center + topWidth / 2},${y}`,
                `${center + bottomWidth / 2},${y + 66}`,
                `${center - bottomWidth / 2},${y + 66}`,
            ].join(' '),
            labelY: y + 30,
            labelX: topWidth < 110 ? center + Math.max(topWidth, bottomWidth) / 2 + 14 : center,
            labelAnchor: topWidth < 110 ? 'start' : 'middle',
            labelColor: topWidth < 110 ? '#475569' : '#ffffff',
        };
    });
});
const activeFunnelStage = computed(() => hoveredFunnelIndex.value === null ? null : funnelChart.value[hoveredFunnelIndex.value] ?? null);

function money(value: number): string {
    try {
        return new Intl.NumberFormat('en-US', { style: 'currency', currency: overview.value?.currency || 'USD', maximumFractionDigits: 2 }).format(value || 0);
    } catch {
        return `$${(value || 0).toLocaleString('en-US', { maximumFractionDigits: 2 })}`;
    }
}
function compactNumber(value: number): string {
    return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(value || 0);
}
function sortDaily(key: GoogleDailySortKey): void {
    if (dailySortKey.value === key) {
        dailySortDirection.value = dailySortDirection.value === 'asc' ? 'desc' : 'asc';
        return;
    }
    dailySortKey.value = key;
    dailySortDirection.value = key === 'date' ? 'desc' : 'asc';
}
function sortMark(key: GoogleDailySortKey): string {
    if (dailySortKey.value !== key) return '↕';
    return dailySortDirection.value === 'asc' ? '↑' : '↓';
}
function sortCampaign(key: GoogleCampaignSortKey): void {
    if (campaignSortKey.value === key) {
        campaignSortDirection.value = campaignSortDirection.value === 'asc' ? 'desc' : 'asc';
        return;
    }
    campaignSortKey.value = key;
    campaignSortDirection.value = ['name', 'channel_type', 'status'].includes(key) ? 'asc' : 'desc';
}
function campaignSortMark(key: GoogleCampaignSortKey): string {
    if (campaignSortKey.value !== key) return '↕';
    return campaignSortDirection.value === 'asc' ? '↑' : '↓';
}
function campaignValueText(row: GoogleCampaign, key: GoogleCampaignSortKey, format: 'text' | 'money' | 'ratio' | 'percent' | 'number'): string {
    const value = row[key];
    if (format === 'text') return String(value);
    if (format === 'money') return money(Number(value));
    if (format === 'ratio') return `${Number(value).toFixed(2)}×`;
    if (format === 'percent') return `${Number(value).toFixed(2)}%`;
    return Number(value).toLocaleString('en-US', { maximumFractionDigits: 2 });
}
function dailyValueText(row: GoogleTrendPoint, key: GoogleDailySortKey, format: 'date' | 'money' | 'ratio' | 'percent' | 'number'): string {
    const value = row[key];
    if (format === 'date') return String(value);
    if (format === 'money') return money(Number(value));
    if (format === 'ratio') return `${Number(value).toFixed(2)}×`;
    if (format === 'percent') return `${Number(value).toFixed(2)}%`;
    return Number(value).toLocaleString('en-US', { maximumFractionDigits: 2 });
}
function secondaryValueText(key: 'impressions' | 'ctr' | 'cpc', format: 'money' | 'number' | 'percent'): string {
    const value = overview.value?.current[key] ?? 0;
    if (format === 'money') return money(value);
    if (format === 'percent') return `${value.toFixed(2)}%`;
    return value.toLocaleString('en-US', { maximumFractionDigits: 2 });
}
function secondaryComparisonText(key: 'impressions' | 'ctr' | 'cpc', format: 'money' | 'number' | 'percent'): string {
    const value = overview.value?.previous[key] ?? 0;
    if (format === 'money') return money(value);
    if (format === 'percent') return `${value.toFixed(2)}%`;
    return value.toLocaleString('en-US', { maximumFractionDigits: 2 });
}
function valueText(key: keyof GoogleSummary, format: 'money' | 'number' | 'ratio'): string {
    const value = overview.value?.current[key] ?? 0;
    if (format === 'money') return money(value);
    if (format === 'ratio') return `${value.toFixed(2)}×`;
    return value.toLocaleString('en-US', { maximumFractionDigits: 2 });
}
function comparisonText(key: keyof GoogleSummary): string {
    const value = overview.value?.previous[key] ?? 0;
    const definition = cardDefinitions.find((card) => card.key === key);
    if (definition?.format === 'money') return money(value);
    if (definition?.format === 'ratio') return `${value.toFixed(2)}×`;
    return value.toLocaleString('en-US', { maximumFractionDigits: 2 });
}
function deltaText(key: keyof GoogleSummary): string {
    const value = overview.value?.deltas[key];
    if (value === null || value === undefined) return '上期为 0';
    if (value === 0) return '0.0%';
    return `${value > 0 ? '+' : ''}${value.toFixed(1)}%`;
}
function deltaClass(key: keyof GoogleSummary): string {
    const value = overview.value?.deltas[key];
    if (value === null || value === undefined || value === 0) return 'text-slate-400';
    return value > 0 ? 'text-emerald-600' : 'text-rose-600';
}
function csrfToken(): string {
    const cookie = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='));
    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}

function broadcastCsrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

function isAdvertisingSyncStatus(payload: unknown): payload is AdvertisingSyncStatus {
    if (!payload || typeof payload !== 'object') return false;
    const record = payload as Record<string, unknown>;
    const state = record.state;
    return typeof record.channel === 'string'
        && (state === 'started' || state === 'progress' || state === 'completed' || state === 'failed')
        && (!record.message || typeof record.message === 'string');
}

function readAdvertisingSyncPayload(value: unknown): AdvertisingSyncStatus | null {
    if (!value || typeof value !== 'object') return null;
    const maybePayload = 'payload' in value && value.payload && typeof value.payload === 'object'
        ? value.payload as Record<string, unknown>
        : 'data' in value && value.data && typeof value.data === 'object'
            ? value.data as Record<string, unknown>
            : value as Record<string, unknown>;
    if (!isAdvertisingSyncStatus(maybePayload)) return null;
    const state = maybePayload.state as AdvertisingSyncStatus['state'];
    const views = Array.isArray(maybePayload.views) ? maybePayload.views.filter((item): item is string => typeof item === 'string') : undefined;
    return {
        channel: maybePayload.channel,
        state,
        mode: typeof maybePayload.mode === 'string' ? maybePayload.mode : undefined,
        source: maybePayload.source === 'google_ads' || maybePayload.source === 'feishu_google_ads' ? maybePayload.source : undefined,
        views,
        progress_percent: typeof maybePayload.progress_percent === 'number' ? maybePayload.progress_percent : undefined,
        last_metric_date: maybePayload.last_metric_date === null || typeof maybePayload.last_metric_date === 'string' ? maybePayload.last_metric_date : undefined,
        data_synced_at: maybePayload.data_synced_at === null || typeof maybePayload.data_synced_at === 'string' ? maybePayload.data_synced_at : undefined,
        message: maybePayload.message === null ? null : (typeof maybePayload.message === 'string' ? maybePayload.message : undefined),
    };
}

const syncGoalStateFromProps = (): void => {
    googleGoalFeishu.value = props.googleGoalFeishu;
    googleGoalSummary.value = props.googleGoalSummary;
};

const refreshGoogleGoals = (): Promise<void> => new Promise((resolve) => {
    router.reload({
        only: ['googleGoalFeishu', 'googleGoalSummary'],
        onSuccess: () => {
            syncGoalStateFromProps();
            resolve();
        },
        onError: () => {
            resolve();
        },
    });
});

const handleSyncCompletion = async (payload: AdvertisingSyncStatus | null): Promise<void> => {
    const views = payload?.views ?? [];
    if (payload?.source === 'google_ads') {
        await loadOverview(false);
        if (isPerformanceView(activeTab.value) && (!views.length || views.includes(activeTab.value))) {
            await loadPerformance(performanceTable.value?.pagination.page ?? 1);
        }
        return;
    }
    if (payload?.source === 'feishu_google_ads') {
        if (activeTab.value === 'weekly') await loadWeeklyReport();
        if (activeTab.value === 'goals') {
            await refreshGoogleGoals();
            feishuViewsDirty.value = false;
        } else {
            feishuViewsDirty.value = true;
        }
        return;
    }
    await loadOverview(false);
    if (isPerformanceView(activeTab.value)) await loadPerformance(performanceTable.value?.pagination.page ?? 1);
};

const stopRealtime = (): void => {
    if (syncEcho && syncChannel.value) syncEcho.leave(syncChannel.value);
    syncEcho?.disconnect();
    syncEcho = null;
};

const connectRealtime = (): void => {
    stopRealtime();
    if (!syncChannel.value || !page.props.realtime.enabled || !page.props.realtime.key) return;

    window.Pusher = Pusher;
    try {
        syncEcho = new Echo({
            broadcaster: 'reverb',
            key: page.props.realtime.key,
            wsHost: window.location.hostname,
            wsPort: window.location.protocol === 'https:' ? 443 : Number(window.location.port || 80),
            wssPort: 443,
            forceTLS: window.location.protocol === 'https:',
            enabledTransports: ['ws', 'wss'],
            authEndpoint: '/broadcasting/auth',
            auth: { headers: { 'X-CSRF-TOKEN': broadcastCsrfToken() } },
        });
        syncEcho.private(syncChannel.value).listen('.advertising-channel.sync-status', (payload: unknown) => {
            const event = readAdvertisingSyncPayload(payload);
            if (!event) return;
            void syncStatusFromEvent(event);
        });
    } catch {
        stopRealtime();
    }
};

const stopPolling = () => { if (poller !== null) { clearInterval(poller); poller = null; } };
const startPolling = () => { if (poller === null) poller = setInterval(refreshStatus, 4000); };
const startHeartbeat = (): void => {
    if (heartbeatPoller !== null) return;
    heartbeatPoller = window.setInterval(() => {
        if (!document.hidden) refreshStatus();
    }, 60_000);
};
const stopHeartbeat = (): void => {
    if (heartbeatPoller !== null) {
        window.clearInterval(heartbeatPoller);
        heartbeatPoller = null;
    }
};
const syncStatusFromEvent = async (payload: AdvertisingSyncStatus): Promise<void> => {
    if (payload.channel !== status.value.channel) return;
    if (payload.source === 'feishu_google_ads') {
        if (payload.state === 'completed' || payload.state === 'failed') await handleSyncCompletion(payload);
        if (payload.state === 'failed' && (activeTab.value === 'weekly' || activeTab.value === 'goals')) {
            toast.error(payload.message || 'Google Ads 飞书数据同步失败。');
        }
        return;
    }
    status.value = {
        ...status.value,
        state: payload.state === 'started' || payload.state === 'progress' ? 'syncing' : payload.state,
        mode: payload.mode ?? status.value.mode,
        progress_percent: payload.progress_percent ?? status.value.progress_percent,
        last_metric_date: payload.last_metric_date ?? status.value.last_metric_date,
        data_synced_at: payload.data_synced_at ?? status.value.data_synced_at,
        message: payload.message ?? status.value.message,
    };

    if (manualSyncPending.value) manualSyncStarted.value = true;

    const finished = payload.state === 'completed' || payload.state === 'failed';
    if (finished) {
        await handleSyncCompletion(payload);
        await refreshStatus(true);
        const shouldNotifyManual = manualSyncPending.value && manualSyncStarted.value;
        if (shouldNotifyManual) {
            manualSyncPending.value = false;
            manualSyncStarted.value = false;
            payload.state === 'failed'
                ? toast.error('Google Ads 数据同步失败，请检查凭证。')
                : toast.success('Google Ads 增量同步完成。');
        }
        if (!polling.value && !manualSyncPending.value) stopPolling();
    }
};
const handleVisibilityChange = (): void => {
    if (!document.hidden) {
        connectRealtime();
        void refreshStatus();
    }
};
const applyFilters = () => {
    if (!filters.value.account || !filters.value.date_from || !filters.value.date_to) return;
    if (filters.value.date_from > filters.value.date_to) return;
    loadOverview();
    if (activeTab.value === 'search-terms' || activeTab.value === 'keywords') loadPerformance(1);
};
const isPerformanceView = (value: string): value is PerformanceView => value === 'search-terms' || value === 'keywords';
const selectTab = (tab: string) => {
    activeTab.value = tab;
    if (tab === 'weekly') {
        if (!weeklyReport.value) loadWeeklyReport();
        return;
    }
    if (tab === 'goals' && feishuViewsDirty.value) {
        void refreshGoogleGoals().finally(() => { feishuViewsDirty.value = false; });
        return;
    }
    if (!isPerformanceView(tab)) return;
    performanceSearch.value = '';
    performanceSort.value = tab === 'keywords' ? 'spend' : 'roas';
    performanceDirection.value = 'desc';
    loadPerformance(1);
};
const loadWeeklyReport = async (week?: string) => {
    if (!isGoogle.value) return;
    const requestId = ++weeklyRequestId;
    weeklyLoading.value = true;
    try {
        const params = new URLSearchParams({ view: 'weekly' });
        if (week) params.set('week', week);
        const response = await fetch(`/paid-advertising/google/data?${params.toString()}`, {
            credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error('周报数据加载失败。');
        const payload = (await response.json() as { data: GoogleWeeklyReport }).data;
        if (requestId !== weeklyRequestId) return;
        weeklyReport.value = payload;
    } catch (error) {
        if (requestId === weeklyRequestId) toast.error(error instanceof Error ? error.message : '周报数据加载失败。');
    } finally {
        if (requestId === weeklyRequestId) weeklyLoading.value = false;
    }
};
const loadPerformance = async (page = 1) => {
    if (!isGoogle.value || !isPerformanceView(activeTab.value)) return;
    const requestId = ++performanceRequestId;
    performanceLoading.value = true;
    try {
        const params = new URLSearchParams({
            account: filters.value.account,
            date_from: filters.value.date_from,
            date_to: filters.value.date_to,
            view: activeTab.value,
            search: performanceSearch.value.trim(),
            sort: performanceSort.value,
            direction: performanceDirection.value,
            page: String(page),
            per_page: '25',
        });
        const response = await fetch(`/paid-advertising/google/data?${params.toString()}`, {
            credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error('表格数据加载失败。');
        const payload = (await response.json() as { data: GooglePerformanceTablePayload }).data;
        if (requestId !== performanceRequestId) return;
        performanceTable.value = payload;
        performanceSort.value = payload.filters.sort;
        performanceDirection.value = payload.filters.direction;
    } catch (error) {
        if (requestId === performanceRequestId) toast.error(error instanceof Error ? error.message : '表格数据加载失败。');
    } finally {
        if (requestId === performanceRequestId) performanceLoading.value = false;
    }
};
const updatePerformanceSearch = (value: string) => {
    performanceSearch.value = value;
    if (performanceSearchTimer !== null) clearTimeout(performanceSearchTimer);
    performanceSearchTimer = setTimeout(() => loadPerformance(1), 300);
};
const sortPerformance = (key: string) => {
    if (performanceSort.value === key) performanceDirection.value = performanceDirection.value === 'asc' ? 'desc' : 'asc';
    else {
        performanceSort.value = key;
        performanceDirection.value = ['keyword', 'search_term', 'matched_keyword', 'campaign_name', 'status'].includes(key) ? 'asc' : 'desc';
    }
    loadPerformance(1);
};
const loadOverview = async (showToast = false) => {
    if (!isGoogle.value || loading.value) return;
    loading.value = true;
    try {
        const params = new URLSearchParams({
            account: filters.value.account,
            date_from: filters.value.date_from,
            date_to: filters.value.date_to,
        });
        const response = await fetch(`/paid-advertising/google/data?${params.toString()}`, {
            credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error('数据加载失败。');
        overview.value = (await response.json() as { data: GoogleOverview }).data;
        filters.value = { ...overview.value.filters, account: overview.value.filters.account ?? '' };
        if (showToast) toast.success('Google Ads 数据已更新。');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : '数据加载失败。');
    } finally {
        loading.value = false;
    }
};
const refreshStatus = async (skipDataRefresh = false) => {
    if (checking.value) return;
    checking.value = true;
    const wasActive = polling.value;
    const previousDataSyncedAt = status.value.data_synced_at;
    try {
        const response = await fetch(`/paid-advertising/${encodeURIComponent(status.value.channel)}/status`, {
            credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error('status failed');
        status.value = (await response.json() as { data: ChannelStatus }).data;
        const backgroundSyncCompleted = !skipDataRefresh
            && !wasActive
            && !polling.value
            && previousDataSyncedAt !== status.value.data_synced_at
            && status.value.data_synced_at !== null;
        if (backgroundSyncCompleted) await handleSyncCompletion({ channel: status.value.channel, state: 'completed', source: 'google_ads' });
        if (manualSyncPending.value && polling.value) manualSyncStarted.value = true;
        const finished = manualSyncPending.value && manualSyncStarted.value && !polling.value;
        if ((wasActive && !polling.value) || finished) {
            await loadOverview(false);
            if (isPerformanceView(activeTab.value)) await loadPerformance(performanceTable.value?.pagination.page ?? 1);
        }
        if (finished) {
            manualSyncPending.value = false;
            manualSyncStarted.value = false;
            status.value.state === 'failed' ? toast.error('Google Ads 数据同步失败，请检查凭证。') : toast.success('Google Ads 增量同步完成。');
        }
        if (manualSyncPending.value && !manualSyncStarted.value && Date.now() - manualSyncRequestedAt.value > 120_000) {
            manualSyncPending.value = false;
            toast.info('同步任务仍在后台排队，稍后会自动执行。');
        }
        if (!polling.value && !manualSyncPending.value) stopPolling();
    } catch { /* The queue remains independent from status polling. */ }
    finally { checking.value = false; }
};
const requestSync = async () => {
    if (syncButtonBusy.value || !props.canSync) return;
    manualSyncing.value = true;
    try {
        const response = await fetch('/paid-advertising/google/sync', {
            method: 'POST', credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': csrfToken() },
            body: '{}',
        });
        const payload = await response.json().catch(() => null) as { message?: string; status?: ChannelStatus; sync?: { already_running?: boolean } } | null;
        if (!response.ok) throw new Error(payload?.message || '同步任务提交失败。');
        if (payload?.status) status.value = payload.status;
        manualSyncPending.value = true;
        manualSyncStarted.value = Boolean(payload?.sync?.already_running || polling.value);
        manualSyncRequestedAt.value = Date.now();
        toast.info(payload?.message || 'Google Ads 增量同步任务已提交。');
        startPolling();
        window.setTimeout(() => void refreshStatus(), 1200);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : '同步任务提交失败。');
    } finally {
        manualSyncing.value = false;
    }
};
const saveGoogleGoalFeishu = async () => {
    const appToken = googleGoalAppToken.value.trim();
    if (!appToken || googleGoalSaving.value || !props.canManageGoogleGoalFeishu) return;
    googleGoalSaving.value = true;
    try {
        const response = await fetch('/paid-advertising/google/goals/feishu', {
            method: 'PUT',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ app_token: appToken }),
        });
        const payload = await response.json().catch(() => null) as { message?: string; data?: GoogleGoalFeishuStatus } | null;
        if (!response.ok) throw new Error(payload?.message || '飞书 App Token 保存失败。');
        if (payload?.data) googleGoalFeishu.value = payload.data;
        googleGoalSummary.value = null;
        googleGoalAppToken.value = '';
        toast.success(payload?.message || '飞书 App Token 已保存。');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : '飞书 App Token 保存失败。');
    } finally {
        googleGoalSaving.value = false;
    }
};
const clearGoogleGoalFeishu = async () => {
    if (googleGoalClearing.value || !props.canManageGoogleGoalFeishu) return;
    if (!window.confirm('确认清除当前店铺的 Google Ads 目标飞书 App Token？')) return;
    googleGoalClearing.value = true;
    try {
        const response = await fetch('/paid-advertising/google/goals/feishu', {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ confirmed: true }),
        });
        const payload = await response.json().catch(() => null) as { message?: string; data?: GoogleGoalFeishuStatus } | null;
        if (!response.ok) throw new Error(payload?.message || '飞书 App Token 清除失败。');
        if (payload?.data) googleGoalFeishu.value = payload.data;
        googleGoalSummary.value = null;
        googleGoalAppToken.value = '';
        toast.success(payload?.message || '飞书 App Token 已清除。');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : '飞书 App Token 清除失败。');
    } finally {
        googleGoalClearing.value = false;
    }
};

onMounted(() => {
    syncGoalStateFromProps();
    connectRealtime();
    startHeartbeat();
    document.addEventListener('visibilitychange', handleVisibilityChange);
    if (polling.value) {
        refreshStatus();
        startPolling();
    }
});
onBeforeUnmount(() => {
    stopPolling();
    stopHeartbeat();
    stopRealtime();
    document.removeEventListener('visibilitychange', handleVisibilityChange);
    if (performanceSearchTimer !== null) clearTimeout(performanceSearchTimer);
});

watch(() => props.googleGoalFeishu, () => {
    syncGoalStateFromProps();
});
watch(() => props.googleGoalSummary, () => {
    syncGoalStateFromProps();
});
watch(() => organizationId.value, () => {
    if (!document.hidden) connectRealtime();
});
</script>

<template>
    <Head :title="status.label" />
    <AppLayout :breadcrumbs="[{ label: '工作台', href: '/dashboard' }, { label: '付费广告' }, { label: status.label }]">
        <div class="mx-auto max-w-[1500px] space-y-6">
            <header class="flex flex-col gap-5 border-b border-slate-200 pb-6 xl:flex-row xl:items-end xl:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-600">当前店铺 · {{ store.name }}</p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">{{ status.label }}</h1>
                    <p class="mt-2 text-sm text-slate-500">广告账户与日指标按店铺独立同步，页面只读取本地 MySQL 数据库。</p>
                </div>
                <span class="inline-flex w-fit items-center gap-2 rounded-full px-3 py-1.5 text-xs font-semibold" :class="status.configured ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">
                    <i class="h-2 w-2 rounded-full" :class="status.configured ? 'bg-emerald-500' : 'bg-amber-500'" />
                    {{ status.configured ? '凭证已配置' : '尚未配置' }}
                </span>
            </header>

            <section v-if="status.state === 'not_configured'" class="rounded-3xl border border-amber-300 bg-white px-7 py-12 shadow-sm sm:px-10 lg:py-16">
                <div class="flex flex-col gap-8 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-amber-600">需要完成配置</p>
                        <h2 class="mt-3 text-2xl font-semibold text-slate-950">{{ status.label }} 尚未配置</h2>
                        <p class="mt-4 max-w-4xl text-sm leading-7 text-slate-500">
                            配置 {{ status.label }} 凭证与广告账号后，系统会先同步账户和最近 7 天数据，再在后台补齐最近半年。
                        </p>
                    </div>
                    <Link :href="status.settings_url" class="inline-flex h-14 shrink-0 items-center justify-center rounded-2xl bg-slate-950 px-8 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">前往配置 {{ status.label }}</Link>
                </div>
            </section>

            <template v-else-if="isGoogle && overview && status.data_ready">
                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="grid gap-4 xl:grid-cols-[minmax(250px,1.2fr)_minmax(390px,1fr)_auto] xl:items-end">
                        <label class="block">
                            <span class="mb-2 block text-sm font-medium text-slate-500">广告账户</span>
                            <select v-model="filters.account" class="h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-800 outline-none ring-blue-500 focus:ring-2" @change="applyFilters">
                                <option v-for="account in overview.accounts" :key="account.id" :value="account.id">{{ account.name }} ({{ account.id }})</option>
                            </select>
                        </label>
                        <div>
                            <span class="mb-2 block text-sm font-medium text-slate-500">统计日期</span>
                            <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-2 rounded-xl border border-slate-200 bg-white px-3">
                                <input v-model="filters.date_from" type="date" class="h-12 min-w-0 border-0 bg-transparent text-sm font-medium text-slate-800 outline-none" @change="applyFilters" />
                                <span class="text-slate-300">—</span>
                                <input v-model="filters.date_to" type="date" class="h-12 min-w-0 border-0 bg-transparent text-sm font-medium text-slate-800 outline-none" @change="applyFilters" />
                            </div>
                        </div>
                        <button type="button" class="inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-slate-950 px-6 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" :disabled="syncButtonBusy || !canSync" :title="canSync ? '同步最近增量数据' : '当前账号没有执行同步的权限'" @click="requestSync">
                            <span class="text-lg" :class="syncButtonBusy ? 'animate-spin' : ''">↻</span>
                            {{ syncButtonBusy ? '同步中' : '同步' }}
                        </button>
                    </div>
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4 text-xs text-slate-500">
                        <span>当前账户：{{ selectedAccount?.name || '—' }} · 币种 {{ overview.currency }}</span>
                        <span>对比上一周期：{{ overview.comparison_period.date_from }} — {{ overview.comparison_period.date_to }}</span>
                    </div>
                </section>

                <nav class="flex gap-2 overflow-x-auto border-b border-slate-200 pb-3">
                    <button v-for="tab in tabs" :key="tab.key" type="button" class="inline-flex h-12 shrink-0 items-center gap-2 rounded-xl border px-4 text-sm font-semibold transition" :class="activeTab === tab.key ? 'border-blue-200 bg-blue-50 text-blue-700 shadow-sm' : 'border-slate-200 bg-white text-slate-500 hover:text-slate-800'" @click="selectTab(tab.key)">
                        <span class="inline-flex h-7 min-w-7 items-center justify-center rounded-lg bg-slate-100 px-1 text-xs" :class="activeTab === tab.key ? 'bg-blue-600 text-white' : ''">{{ tab.icon }}</span>
                        {{ tab.label }}
                    </button>
                </nav>

                <section v-if="activeTab === 'overview'" class="relative rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 class="text-xl font-semibold text-slate-950">数据总览</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ filters.date_from }} — {{ filters.date_to }}，与紧邻的上一等长周期对比</p>
                        </div>
                        <span class="rounded-full bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-500">数据截止 {{ status.last_metric_date || '—' }}</span>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <article v-for="card in cardDefinitions" :key="card.key" class="rounded-2xl border border-t-4 bg-white px-5 py-5 shadow-sm" :style="{ borderColor: card.color }">
                            <p class="text-sm font-medium text-slate-500">{{ card.label }}</p>
                            <p class="mt-3 text-2xl font-semibold tracking-tight text-slate-950">{{ valueText(card.key, card.format) }}</p>
                            <div class="mt-4 flex items-center justify-between gap-2 text-xs">
                                <span class="font-semibold" :class="deltaClass(card.key)">{{ deltaText(card.key) }}</span>
                                <span class="text-slate-400">上期 {{ comparisonText(card.key) }}</span>
                            </div>
                        </article>
                    </div>

                    <div class="mt-6 grid gap-5 xl:grid-cols-2">
                        <article class="relative min-w-0 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50/40 p-5">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-base font-semibold text-slate-900">花费 &amp; 销售额 &amp; ROI 趋势</h3>
                                    <p class="mt-1 text-xs text-slate-400">按日期升序 · 每日 MySQL 汇总</p>
                                </div>
                                <div class="flex flex-wrap gap-3 text-xs text-slate-500">
                                    <span class="inline-flex items-center gap-1.5"><i class="h-2.5 w-5 rounded bg-blue-500" />花费</span>
                                    <span class="inline-flex items-center gap-1.5"><i class="h-2.5 w-5 rounded bg-emerald-500" />销售额</span>
                                    <span class="inline-flex items-center gap-1.5"><i class="h-0.5 w-5 bg-orange-500" />ROI</span>
                                </div>
                            </div>
                            <div v-if="trendChart.points.length" class="relative mt-4 w-full" @mouseleave="hoveredTrendIndex = null">
                                <svg v-readable-chart class="h-auto w-full" :viewBox="`0 0 ${trendChart.width} ${trendChart.height}`" role="img" aria-label="Google Ads 每日花费、销售额与 ROI 趋势">
                                    <g v-for="tick in trendChart.ticks" :key="tick.y">
                                        <line :x1="trendChart.left" :x2="trendChart.width - trendChart.right" :y1="tick.y" :y2="tick.y" stroke="#e2e8f0" stroke-dasharray="4 5" />
                                        <text :x="trendChart.left - 9" :y="tick.y + 4" text-anchor="end" fill="#94a3b8" font-size="12">{{ compactNumber(tick.amount) }}</text>
                                        <text :x="trendChart.width - trendChart.right + 9" :y="tick.y + 4" fill="#94a3b8" font-size="12">{{ tick.roi.toFixed(1) }}×</text>
                                    </g>
                                    <text :x="trendChart.left" y="40" fill="#94a3b8" font-size="12">金额</text>
                                    <text :x="trendChart.width - trendChart.right" y="40" text-anchor="end" fill="#94a3b8" font-size="12">ROI</text>
                                    <g v-for="point in trendChart.points" :key="point.date" class="cursor-pointer" @mouseenter="hoveredTrendIndex = point.index">
                                        <rect :x="point.x - trendChart.barWidth - 1" :y="point.spendY" :width="trendChart.barWidth" :height="trendChart.top + trendChart.plotHeight - point.spendY" rx="2" fill="#4f86ed" opacity="0.9" />
                                        <rect :x="point.x + 1" :y="point.revenueY" :width="trendChart.barWidth" :height="trendChart.top + trendChart.plotHeight - point.revenueY" rx="2" fill="#46a065" opacity="0.9" />
                                        <rect :x="point.x - trendChart.step / 2" :y="trendChart.top" :width="trendChart.step" :height="trendChart.plotHeight" fill="transparent" />
                                        <text v-if="point.showLabel" :x="point.x" :y="trendChart.top + trendChart.plotHeight + 23" text-anchor="middle" fill="#64748b" font-size="12">{{ point.dateLabel }}</text>
                                    </g>
                                    <polyline :points="trendChart.linePoints" fill="none" stroke="#ed7d31" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                                    <circle v-for="point in trendChart.points" :key="`roi-${point.date}`" :cx="point.x" :cy="point.roiY" r="4" fill="#ed7d31" stroke="white" stroke-width="2" />
                                    <g v-if="activeTrendPoint">
                                        <line :x1="activeTrendPoint.x" :x2="activeTrendPoint.x" :y1="trendChart.top" :y2="trendChart.top + trendChart.plotHeight" stroke="#64748b" stroke-dasharray="5 5" />
                                    </g>
                                </svg>
                                <div v-if="activeTrendPoint" class="pointer-events-none absolute right-3 top-3 min-w-52 rounded-xl border border-slate-200 bg-white/95 p-4 text-xs shadow-xl backdrop-blur">
                                    <p class="mb-3 text-sm font-semibold text-slate-900">{{ activeTrendPoint.date }}</p>
                                    <div class="space-y-2 text-slate-500">
                                        <p class="flex justify-between gap-5"><span>花费</span><strong class="text-slate-900">{{ money(activeTrendPoint.spend) }}</strong></p>
                                        <p class="flex justify-between gap-5"><span>销售额</span><strong class="text-slate-900">{{ money(activeTrendPoint.revenue) }}</strong></p>
                                        <p class="flex justify-between gap-5"><span>ROI</span><strong class="text-orange-600">{{ activeTrendPoint.roi.toFixed(2) }}×</strong></p>
                                    </div>
                                </div>
                            </div>
                            <div v-else class="grid min-h-72 place-items-center text-sm text-slate-400">所选时间段暂无趋势数据</div>
                        </article>

                        <article class="relative min-w-0 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50/40 p-5">
                            <div>
                                <h3 class="text-base font-semibold text-slate-900">转化漏斗</h3>
                                <p class="mt-1 text-xs text-slate-400">曝光 → 点击 → Google Ads 转化</p>
                            </div>
                            <div v-if="funnelChart.length" class="relative mt-4" @mouseleave="hoveredFunnelIndex = null">
                                <svg v-readable-chart class="mx-auto h-auto w-full max-w-[620px]" viewBox="0 0 560 300" role="img" aria-label="Google Ads 曝光、点击与转化漏斗">
                                    <g v-for="stage in funnelChart" :key="stage.key" class="cursor-pointer" @mouseenter="hoveredFunnelIndex = stage.index">
                                        <polygon :points="stage.points" :fill="stage.color" opacity="0.94" stroke="white" stroke-width="2" />
                                        <text :x="stage.labelX" :y="stage.labelY" :text-anchor="stage.labelAnchor" :fill="stage.labelColor" font-size="13" font-weight="600">{{ stage.label }}</text>
                                        <text :x="stage.labelX" :y="stage.labelY + 18" :text-anchor="stage.labelAnchor" :fill="stage.labelColor" font-size="13" font-weight="700">{{ stage.value.toLocaleString('en-US', { maximumFractionDigits: 2 }) }}</text>
                                    </g>
                                </svg>
                                <div v-if="activeFunnelStage" class="pointer-events-none absolute right-3 top-3 rounded-xl border border-slate-200 bg-white/95 px-4 py-3 text-xs shadow-xl">
                                    <span class="text-slate-500">{{ activeFunnelStage.label }}</span>
                                    <strong class="ml-4 text-slate-900">{{ activeFunnelStage.value.toLocaleString('en-US', { maximumFractionDigits: 2 }) }}</strong>
                                </div>
                                <div class="mt-2 grid grid-cols-3 gap-2 text-center text-xs text-slate-500">
                                    <span>曝光基数 100%</span>
                                    <span>CTR {{ overview.current.ctr.toFixed(2) }}%</span>
                                    <span>点击转化率 {{ overview.current.clicks > 0 ? ((overview.current.conversions / overview.current.clicks) * 100).toFixed(2) : '0.00' }}%</span>
                                </div>
                            </div>
                            <div v-else class="grid min-h-72 place-items-center text-sm text-slate-400">所选时间段暂无漏斗数据</div>
                        </article>
                    </div>

                    <div class="mt-5 grid gap-4 md:grid-cols-3">
                        <article v-for="card in secondaryCardDefinitions" :key="card.key" class="rounded-2xl border border-t-4 bg-white px-5 py-5 shadow-sm" :style="{ borderColor: card.color }">
                            <p class="text-sm font-medium text-slate-500">{{ card.label }}</p>
                            <p class="mt-3 text-2xl font-semibold tracking-tight text-slate-950">{{ secondaryValueText(card.key, card.format) }}</p>
                            <div class="mt-4 flex items-center justify-between gap-2 text-xs">
                                <span class="font-semibold" :class="deltaClass(card.key)">{{ deltaText(card.key) }}</span>
                                <span class="text-slate-400">上期 {{ secondaryComparisonText(card.key, card.format) }}</span>
                            </div>
                        </article>
                    </div>
                    <div v-if="loading" class="absolute inset-0 grid place-items-center rounded-3xl bg-white/70 text-sm font-medium text-slate-500 backdrop-blur-[1px]">正在读取 MySQL 数据…</div>
                </section>
                <section v-else-if="activeTab === 'trend'" class="space-y-5">
                    <article class="relative min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <h2 class="text-xl font-semibold text-slate-950">每日花费 &amp; 销售额 &amp; ROI</h2>
                                <p class="mt-1 text-sm text-slate-500">{{ filters.date_from }} — {{ filters.date_to }} · 账户日粒度数据</p>
                            </div>
                            <div class="flex flex-wrap gap-4 text-xs text-slate-500">
                                <span class="inline-flex items-center gap-1.5"><i class="h-3 w-6 rounded bg-blue-500" />花费</span>
                                <span class="inline-flex items-center gap-1.5"><i class="h-3 w-6 rounded bg-emerald-500" />销售额</span>
                                <span class="inline-flex items-center gap-1.5"><i class="h-0.5 w-6 bg-orange-500" />ROI</span>
                            </div>
                        </div>
                        <div v-if="trendChart.points.length" class="relative mt-5 w-full" @mouseleave="hoveredTrendIndex = null">
                            <svg v-readable-chart class="h-auto min-h-[300px] w-full" :viewBox="`0 0 ${trendChart.width} ${trendChart.height}`" role="img" aria-label="Google Ads 每日花费、销售额与 ROI 趋势">
                                <g v-for="tick in trendChart.ticks" :key="tick.y">
                                    <line :x1="trendChart.left" :x2="trendChart.width - trendChart.right" :y1="tick.y" :y2="tick.y" stroke="#e2e8f0" stroke-dasharray="4 5" />
                                    <text :x="trendChart.left - 9" :y="tick.y + 4" text-anchor="end" fill="#94a3b8" font-size="12">{{ compactNumber(tick.amount) }}</text>
                                    <text :x="trendChart.width - trendChart.right + 9" :y="tick.y + 4" fill="#94a3b8" font-size="12">{{ tick.roi.toFixed(1) }}×</text>
                                </g>
                                <text :x="trendChart.left" y="40" fill="#94a3b8" font-size="12">金额</text>
                                <text :x="trendChart.width - trendChart.right" y="40" text-anchor="end" fill="#94a3b8" font-size="12">ROI</text>
                                <g v-for="point in trendChart.points" :key="`trend-${point.date}`" class="cursor-pointer" @mouseenter="hoveredTrendIndex = point.index">
                                    <rect :x="point.x - trendChart.barWidth - 1" :y="point.spendY" :width="trendChart.barWidth" :height="trendChart.top + trendChart.plotHeight - point.spendY" rx="2" fill="#4f86ed" opacity="0.92" />
                                    <rect :x="point.x + 1" :y="point.revenueY" :width="trendChart.barWidth" :height="trendChart.top + trendChart.plotHeight - point.revenueY" rx="2" fill="#46a065" opacity="0.92" />
                                    <rect :x="point.x - trendChart.step / 2" :y="trendChart.top" :width="trendChart.step" :height="trendChart.plotHeight" fill="transparent" />
                                    <text v-if="point.showLabel" :x="point.x" :y="trendChart.top + trendChart.plotHeight + 23" text-anchor="middle" fill="#64748b" font-size="12">{{ point.dateLabel }}</text>
                                </g>
                                <polyline :points="trendChart.linePoints" fill="none" stroke="#ed7d31" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                                <circle v-for="point in trendChart.points" :key="`trend-roi-${point.date}`" :cx="point.x" :cy="point.roiY" r="4" fill="#ed7d31" stroke="white" stroke-width="2" />
                                <line v-if="activeTrendPoint" :x1="activeTrendPoint.x" :x2="activeTrendPoint.x" :y1="trendChart.top" :y2="trendChart.top + trendChart.plotHeight" stroke="#64748b" stroke-dasharray="5 5" />
                            </svg>
                            <div v-if="activeTrendPoint" class="pointer-events-none absolute right-3 top-3 min-w-56 rounded-xl border border-slate-200 bg-white/95 p-4 text-xs shadow-xl backdrop-blur">
                                <p class="mb-3 text-sm font-semibold text-slate-900">{{ activeTrendPoint.date }}</p>
                                <div class="space-y-2 text-slate-500">
                                    <p class="flex justify-between gap-5"><span>花费</span><strong class="text-blue-600">{{ money(activeTrendPoint.spend) }}</strong></p>
                                    <p class="flex justify-between gap-5"><span>销售额</span><strong class="text-emerald-600">{{ money(activeTrendPoint.revenue) }}</strong></p>
                                    <p class="flex justify-between gap-5"><span>ROI</span><strong class="text-orange-600">{{ activeTrendPoint.roi.toFixed(2) }}×</strong></p>
                                </div>
                            </div>
                        </div>
                        <div v-else class="grid min-h-80 place-items-center text-sm text-slate-400">所选时间段暂无趋势数据</div>
                    </article>

                    <div class="grid gap-5 xl:grid-cols-2">
                        <article class="relative min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            <h3 class="text-lg font-semibold text-slate-950">每日 CTR 趋势</h3>
                            <p class="mt-1 text-xs text-slate-400">点击数 ÷ 曝光数</p>
                            <div v-if="ctrChart.points.length" class="relative mt-5" @mouseleave="hoveredCtrIndex = null">
                                <svg v-readable-chart class="h-auto w-full" :viewBox="`0 0 ${ctrChart.width} ${ctrChart.height}`" role="img" aria-label="Google Ads 每日 CTR 趋势">
                                    <defs>
                                        <linearGradient id="googleCtrArea" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="0%" stop-color="#3b82f6" stop-opacity="0.28" />
                                            <stop offset="100%" stop-color="#3b82f6" stop-opacity="0.02" />
                                        </linearGradient>
                                    </defs>
                                    <g v-for="tick in ctrChart.ticks" :key="`ctr-${tick.y}`">
                                        <line :x1="ctrChart.left" :x2="ctrChart.width - ctrChart.right" :y1="tick.y" :y2="tick.y" stroke="#e2e8f0" stroke-dasharray="4 5" />
                                        <text :x="ctrChart.left - 9" :y="tick.y + 4" text-anchor="end" fill="#94a3b8" font-size="12">{{ tick.value.toFixed(1) }}%</text>
                                    </g>
                                    <polygon :points="ctrChart.areaPoints" fill="url(#googleCtrArea)" />
                                    <polyline :points="ctrChart.linePoints" fill="none" stroke="#3b82f6" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                                    <g v-for="point in ctrChart.points" :key="`ctr-point-${point.date}`" class="cursor-pointer" @mouseenter="hoveredCtrIndex = point.index">
                                        <rect :x="point.hitX" :y="ctrChart.top" :width="point.hitWidth" :height="ctrChart.plotHeight" fill="transparent" />
                                        <circle :cx="point.x" :cy="point.y" r="4" fill="#3b82f6" stroke="white" stroke-width="2" />
                                        <text v-if="point.showLabel" :x="point.x" :y="ctrChart.top + ctrChart.plotHeight + 24" text-anchor="middle" fill="#64748b" font-size="12">{{ point.date.slice(5) }}</text>
                                    </g>
                                    <line v-if="activeCtrPoint" :x1="activeCtrPoint.x" :x2="activeCtrPoint.x" :y1="ctrChart.top" :y2="ctrChart.top + ctrChart.plotHeight" stroke="#64748b" stroke-dasharray="5 5" />
                                </svg>
                                <div v-if="activeCtrPoint" class="pointer-events-none absolute right-3 top-3 rounded-xl border border-slate-200 bg-white/95 px-4 py-3 text-xs shadow-xl">
                                    <span class="text-slate-500">{{ activeCtrPoint.date }}</span>
                                    <strong class="ml-4 text-blue-600">{{ activeCtrPoint.ctr.toFixed(2) }}%</strong>
                                </div>
                            </div>
                            <div v-else class="grid min-h-72 place-items-center text-sm text-slate-400">暂无 CTR 数据</div>
                        </article>

                        <article class="relative min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            <h3 class="text-lg font-semibold text-slate-950">广告系列花费占比</h3>
                            <p class="mt-1 text-xs text-slate-400">当前时间段花费最高的 6 个广告系列</p>
                            <div v-if="campaignPie.slices.length" class="relative mt-5 grid items-center gap-4 sm:grid-cols-[minmax(280px,1fr)_minmax(180px,0.8fr)]" @mouseleave="hoveredCampaignIndex = null">
                                <svg v-readable-chart class="mx-auto h-auto w-full max-w-[360px]" viewBox="0 0 320 320" role="img" aria-label="Google Ads 广告系列花费占比">
                                    <circle v-if="campaignPie.slices.length === 1" :cx="campaignPie.center" :cy="campaignPie.center" :r="campaignPie.radius" :fill="campaignPie.slices[0].color" class="cursor-pointer" @mouseenter="hoveredCampaignIndex = 0" />
                                    <template v-for="slice in campaignPie.slices" :key="slice.id">
                                        <path v-if="campaignPie.slices.length > 1" :d="slice.path" :fill="slice.color" stroke="white" stroke-width="2" class="cursor-pointer transition-opacity" :opacity="hoveredCampaignIndex === null || hoveredCampaignIndex === slice.index ? 1 : 0.45" @mouseenter="hoveredCampaignIndex = slice.index" />
                                        <text :x="slice.labelX" :y="slice.labelY" text-anchor="middle" fill="white" font-size="13" font-weight="700">{{ slice.percentage >= 5 ? `${slice.percentage.toFixed(1)}%` : '' }}</text>
                                    </template>
                                </svg>
                                <div class="space-y-2 text-xs text-slate-500">
                                    <button v-for="slice in campaignPie.slices" :key="`legend-${slice.id}`" type="button" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left transition hover:bg-slate-50" @mouseenter="hoveredCampaignIndex = slice.index" @mouseleave="hoveredCampaignIndex = null">
                                        <i class="h-3 w-3 shrink-0 rounded" :style="{ backgroundColor: slice.color }" />
                                        <span class="min-w-0 flex-1 truncate" :title="slice.name">{{ slice.name }}</span>
                                        <strong class="text-slate-700">{{ slice.percentage.toFixed(1) }}%</strong>
                                    </button>
                                </div>
                                <div v-if="activeCampaign" class="pointer-events-none absolute left-1/2 top-1/2 min-w-56 -translate-x-1/2 -translate-y-1/2 rounded-xl border border-slate-200 bg-white/95 p-4 text-xs shadow-xl">
                                    <p class="max-w-64 font-semibold text-slate-900">{{ activeCampaign.name }}</p>
                                    <p class="mt-2 flex justify-between gap-5 text-slate-500"><span>花费</span><strong class="text-slate-900">{{ money(activeCampaign.spend) }}</strong></p>
                                    <p class="mt-1 flex justify-between gap-5 text-slate-500"><span>占比</span><strong class="text-blue-600">{{ activeCampaign.percentage.toFixed(2) }}%</strong></p>
                                </div>
                            </div>
                            <div v-else class="grid min-h-72 place-items-center text-sm text-slate-400">所选时间段暂无广告系列数据</div>
                        </article>
                    </div>

                    <article class="relative overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-wrap items-end justify-between gap-3 border-b border-slate-100 px-6 py-5">
                            <div>
                                <h3 class="text-lg font-semibold text-slate-950">每日数据明细</h3>
                                <p class="mt-1 text-xs text-slate-400">共 {{ sortedDailyRows.length }} 条 · 点击表头排序</p>
                            </div>
                            <span class="text-xs text-slate-400">Conv. value 按转化日期口径</span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[1220px] border-collapse text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500">
                                    <tr>
                                        <th v-for="column in dailyColumns" :key="column.key" class="whitespace-nowrap border-b border-slate-200 px-5 py-4">
                                            <button type="button" class="inline-flex items-center gap-2 transition hover:text-blue-600" @click="sortDaily(column.key)">
                                                {{ column.label }} <span class="text-xs">{{ sortMark(column.key) }}</span>
                                            </button>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 text-slate-700">
                                    <tr v-for="row in sortedDailyRows" :key="row.date" class="transition hover:bg-blue-50/40">
                                        <td v-for="column in dailyColumns" :key="`${row.date}-${column.key}`" class="whitespace-nowrap px-5 py-4 font-medium" :class="column.key === 'roas' || column.key === 'roi' ? 'text-emerald-600' : ''">
                                            {{ dailyValueText(row, column.key, column.format) }}
                                        </td>
                                    </tr>
                                    <tr v-if="!sortedDailyRows.length"><td :colspan="dailyColumns.length" class="px-5 py-16 text-center text-sm text-slate-400">所选时间段暂无每日数据</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="loading" class="absolute inset-0 grid place-items-center bg-white/70 text-sm font-medium text-slate-500 backdrop-blur-[1px]">正在读取 MySQL 数据…</div>
                    </article>
                </section>
                <section v-else-if="activeTab === 'campaigns'" class="space-y-5">
                    <div class="grid gap-5 xl:grid-cols-2">
                        <article class="relative min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                            <h2 class="text-xl font-semibold text-slate-950">广告系列花费占比</h2>
                            <p class="mt-1 text-sm text-slate-500">当前时间段花费最高的 6 个广告系列</p>
                            <div v-if="campaignPie.slices.length" class="relative mt-5 grid items-center gap-4 sm:grid-cols-[minmax(260px,1fr)_minmax(180px,0.8fr)]" @mouseleave="hoveredCampaignIndex = null">
                                <svg v-readable-chart class="mx-auto h-auto w-full max-w-[340px]" viewBox="0 0 320 320" role="img" aria-label="Google Ads 广告系列花费占比">
                                    <circle v-if="campaignPie.slices.length === 1" :cx="campaignPie.center" :cy="campaignPie.center" :r="campaignPie.radius" :fill="campaignPie.slices[0].color" class="cursor-pointer" @mouseenter="hoveredCampaignIndex = 0" />
                                    <template v-for="slice in campaignPie.slices" :key="`campaign-tab-${slice.id}`">
                                        <path v-if="campaignPie.slices.length > 1" :d="slice.path" :fill="slice.color" stroke="white" stroke-width="2" class="cursor-pointer transition-opacity" :opacity="hoveredCampaignIndex === null || hoveredCampaignIndex === slice.index ? 1 : 0.45" @mouseenter="hoveredCampaignIndex = slice.index" />
                                        <text :x="slice.labelX" :y="slice.labelY" text-anchor="middle" fill="white" font-size="13" font-weight="700">{{ slice.percentage >= 5 ? `${slice.percentage.toFixed(1)}%` : '' }}</text>
                                    </template>
                                </svg>
                                <div class="space-y-2 text-xs text-slate-500">
                                    <button v-for="slice in campaignPie.slices" :key="`campaign-tab-legend-${slice.id}`" type="button" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left transition hover:bg-slate-50" @mouseenter="hoveredCampaignIndex = slice.index" @mouseleave="hoveredCampaignIndex = null">
                                        <i class="h-3 w-3 shrink-0 rounded" :style="{ backgroundColor: slice.color }" />
                                        <span class="min-w-0 flex-1 truncate" :title="slice.name">{{ slice.name }}</span>
                                        <strong class="text-slate-700">{{ slice.percentage.toFixed(1) }}%</strong>
                                    </button>
                                </div>
                                <div v-if="activeCampaign" class="pointer-events-none absolute left-1/2 top-1/2 min-w-56 -translate-x-1/2 -translate-y-1/2 rounded-xl border border-slate-200 bg-white/95 p-4 text-xs shadow-xl">
                                    <p class="max-w-64 font-semibold text-slate-900">{{ activeCampaign.name }}</p>
                                    <p class="mt-2 flex justify-between gap-5 text-slate-500"><span>花费</span><strong class="text-slate-900">{{ money(activeCampaign.spend) }}</strong></p>
                                    <p class="mt-1 flex justify-between gap-5 text-slate-500"><span>占比</span><strong class="text-blue-600">{{ activeCampaign.percentage.toFixed(2) }}%</strong></p>
                                </div>
                            </div>
                            <div v-else class="grid min-h-72 place-items-center text-sm text-slate-400">所选时间段暂无广告系列数据</div>
                        </article>

                        <article class="min-w-0 overflow-hidden rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                            <h2 class="text-xl font-semibold text-slate-950">广告系列 ROAS 对比</h2>
                            <p class="mt-1 text-sm text-slate-500">同一组花费最高的 6 个广告系列</p>
                            <div v-if="topCampaigns.length" class="mt-8 space-y-5">
                                <div v-for="campaign in topCampaigns" :key="`roas-${campaign.id}`" class="grid grid-cols-[minmax(120px,0.65fr)_minmax(180px,1.35fr)_auto] items-center gap-3 text-sm" :title="`${campaign.name} · ROAS ${campaign.roas.toFixed(2)}×`">
                                    <span class="truncate font-medium text-slate-500">{{ campaign.name }}</span>
                                    <div class="h-8 overflow-hidden rounded-lg bg-slate-100">
                                        <div class="h-full min-w-1 rounded-lg transition-[width] duration-500" :class="campaign.roas >= 5 ? 'bg-emerald-500' : campaign.roas >= 3 ? 'bg-orange-400' : 'bg-rose-500'" :style="{ width: `${Math.max(1.5, (campaign.roas / campaignRoasMax) * 100)}%` }" />
                                    </div>
                                    <strong class="w-16 text-right tabular-nums text-slate-700">{{ campaign.roas.toFixed(2) }}×</strong>
                                </div>
                            </div>
                            <div v-else class="grid min-h-72 place-items-center text-sm text-slate-400">所选时间段暂无 ROAS 数据</div>
                        </article>
                    </div>

                    <article class="relative overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-wrap items-end justify-between gap-4 border-b border-slate-100 px-6 py-5">
                            <div>
                                <h2 class="text-xl font-semibold text-slate-950">广告系列明细</h2>
                                <p class="mt-1 text-xs text-slate-400">共 {{ filteredCampaigns.length }} 条 · 点击表头排序</p>
                            </div>
                            <label class="block w-full sm:w-80">
                                <span class="sr-only">筛选广告系列</span>
                                <input v-model="campaignSearch" type="search" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-800 outline-none ring-blue-500 placeholder:text-slate-400 focus:ring-2" placeholder="输入广告系列名称或类型，自动筛选…" />
                            </label>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[1320px] border-collapse text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500">
                                    <tr>
                                        <th v-for="column in campaignColumns" :key="column.key" class="whitespace-nowrap border-b border-slate-200 px-4 py-4" :class="column.key === 'name' ? 'min-w-64' : ''">
                                            <button type="button" class="inline-flex items-center gap-2 transition hover:text-blue-600" @click="sortCampaign(column.key)">
                                                {{ column.label }} <span class="text-xs">{{ campaignSortMark(column.key) }}</span>
                                            </button>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 text-slate-700">
                                    <tr v-for="campaign in filteredCampaigns" :key="campaign.id" class="transition hover:bg-blue-50/40">
                                        <td v-for="column in campaignColumns" :key="`${campaign.id}-${column.key}`" class="whitespace-nowrap px-4 py-4 font-medium" :class="column.key === 'roas' ? (campaign.roas >= 4 ? 'text-emerald-600' : 'text-rose-600') : ''">
                                            <span v-if="column.key === 'name'" class="block max-w-72 truncate text-slate-900" :title="campaign.name">{{ campaign.name }}</span>
                                            <span v-else-if="column.key === 'channel_type'" class="inline-flex rounded-lg bg-slate-100 px-2.5 py-1 text-xs text-slate-600">{{ campaign.channel_type }}</span>
                                            <span v-else-if="column.key === 'status'" class="inline-flex rounded-lg px-2.5 py-1 text-xs" :class="campaign.status === '运行中' ? 'bg-emerald-50 text-emerald-700' : campaign.status === '已暂停' ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600'">{{ campaign.status }}</span>
                                            <template v-else>{{ campaignValueText(campaign, column.key, column.format) }}</template>
                                        </td>
                                    </tr>
                                    <tr v-if="!filteredCampaigns.length"><td :colspan="campaignColumns.length" class="px-5 py-16 text-center text-sm text-slate-400">所选时间段暂无匹配的广告系列</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="loading" class="absolute inset-0 grid place-items-center bg-white/70 text-sm font-medium text-slate-500 backdrop-blur-[1px]">正在读取 MySQL 数据…</div>
                    </article>
                </section>
                <GooglePerformanceTable
                    v-else-if="activeTab === 'search-terms' || activeTab === 'keywords'"
                    :view="activeTab"
                    :table="performanceTable"
                    :loading="performanceLoading"
                    :search="performanceSearch"
                    :currency="overview.currency"
                    @update:search="updatePerformanceSearch"
                    @sort="sortPerformance"
                    @page="loadPerformance"
                />
                <GoogleAdsWeeklyReport
                    v-else-if="activeTab === 'weekly'"
                    :report="weeklyReport"
                    :loading="weeklyLoading"
                    :currency="overview.currency"
                    @select="loadWeeklyReport"
                />

                <section v-else-if="activeTab === 'goals'" class="relative min-h-[420px]">
                    <template v-if="googleGoalFeishu?.configured">
                        <button
                            v-if="canManageGoogleGoalFeishu"
                            type="button"
                            class="absolute right-5 top-5 z-10 inline-flex h-9 w-9 items-center justify-center rounded-full border border-slate-200 bg-white text-xl leading-none text-slate-400 shadow-sm transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-600 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="googleGoalClearing"
                            aria-label="清除飞书 App Token"
                            title="清除飞书 App Token"
                            @click="clearGoogleGoalFeishu"
                        >
                            {{ googleGoalClearing ? '…' : '×' }}
                        </button>
                        <GoogleAdsGoalTemplate :summary="googleGoalSummary" board-name="销售目标" />
                    </template>
                    <div v-else class="flex min-h-[420px] flex-col items-center justify-center rounded-3xl border border-slate-200 bg-white px-6 py-14 text-center shadow-sm">
                        <span class="inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-blue-50 text-2xl text-blue-600 ring-1 ring-blue-100">⌁</span>
                        <p class="mt-6 text-xs font-semibold uppercase tracking-[0.18em] text-blue-600">Google Ads 目标数据源</p>
                        <h2 class="mt-3 text-2xl font-semibold tracking-tight text-slate-950">设置飞书 App Token</h2>
                        <p class="mt-3 max-w-xl text-sm leading-7 text-slate-500">配置当前店铺的飞书多维表格 App Token，系统会自动发现并同步其中的目标数据表。</p>
                        <form v-if="canManageGoogleGoalFeishu" class="mt-7 flex w-full max-w-2xl flex-col gap-3 sm:flex-row" @submit.prevent="saveGoogleGoalFeishu">
                            <label class="sr-only" for="google-goal-feishu-token">飞书 App Token</label>
                            <input
                                id="google-goal-feishu-token"
                                v-model="googleGoalAppToken"
                                type="password"
                                autocomplete="off"
                                class="h-12 min-w-0 flex-1 rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 outline-none ring-blue-500 placeholder:text-slate-400 focus:ring-2"
                                placeholder="输入飞书多维表格 App Token"
                            />
                            <button type="submit" class="inline-flex h-12 items-center justify-center rounded-xl bg-slate-950 px-7 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" :disabled="googleGoalSaving || !googleGoalAppToken.trim()">
                                {{ googleGoalSaving ? '保存中…' : '保存' }}
                            </button>
                        </form>
                        <p v-else class="mt-7 rounded-xl bg-amber-50 px-5 py-3 text-sm text-amber-700">当前账号没有修改店铺配置的权限，请联系店铺管理员设置。</p>
                    </div>
                </section>

                <section v-else class="min-h-[420px] rounded-3xl border border-slate-200 bg-white shadow-sm" />
            </template>

            <section v-else class="overflow-hidden rounded-3xl border bg-white shadow-sm" :class="status.data_ready ? 'border-emerald-200' : 'border-blue-200'">
                <div class="h-1 bg-gradient-to-r from-blue-600 via-cyan-400 to-emerald-400" />
                <div class="px-7 py-8 sm:px-10">
                    <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.18em]" :class="status.data_ready ? 'text-emerald-600' : 'text-blue-600'">{{ status.data_ready ? '首批数据已就绪' : '后台静默同步' }}</p>
                            <h2 class="mt-2 text-xl font-semibold text-slate-950">{{ status.state === 'backfilling' ? '页面已可用，正在补齐历史' : status.data_ready ? '数据已可使用' : '正在准备最近 7 天数据' }}</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-500">{{ status.message }}</p>
                        </div>
                        <div class="rounded-2xl bg-slate-50 px-5 py-4 ring-1 ring-slate-100"><p class="text-xs font-medium text-slate-400">数据新鲜度</p><p class="mt-1 text-sm font-semibold text-slate-900">{{ freshness }}</p></div>
                    </div>
                    <div v-if="polling && status.total_chunks" class="mt-7">
                        <div class="mb-2 flex justify-between text-xs font-semibold"><span class="text-slate-500">{{ status.completed_chunks }} / {{ status.total_chunks }} 个分片</span><span class="text-blue-600">{{ status.progress_percent.toFixed(1) }}%</span></div>
                        <div class="h-3 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-gradient-to-r from-blue-600 to-cyan-400 transition-[width] duration-700" :style="{ width: progressWidth }" /></div>
                    </div>
                    <div v-if="status.data_ready && !isGoogle" class="mt-7 rounded-2xl border border-dashed border-slate-200 bg-slate-50/70 px-5 py-8 text-center text-sm text-slate-500">数据模板将在下一步接入；当前账户与日指标已写入店铺独立数据库。</div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
