<script setup lang="ts">
import { readableMapTextSize } from '../../utils/typography';
import type { DataDrivenPropertyValueSpecification } from 'maplibre-gl';
import { Head } from '@inertiajs/vue3';
import type { Feature, FeatureCollection, Point } from 'geojson';
import { AttributionControl, FullscreenControl, Map, NavigationControl, Popup, setWorkerUrl, type GeoJSONSource, type Map as MapLibreMap, type PaddingOptions } from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import mapWorkerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?worker&url';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';

setWorkerUrl(mapWorkerUrl);

interface Metric { value: number; available: boolean; message: string | null; trend: number[]; classification?: string }
interface LiveInsight { available: boolean; message: string; classification?: string; items: Array<{ label: string; value: number }> }
interface LiveLocation { id: string; label: string; latitude: number; longitude: number; visitors: number; orders: number }
interface LiveSnapshot {
    schema: string;
    store: { id: number; name: string; currency: string; timezone: string };
    period: { label: string; date: string; from: string; to: string; current_visitors_minutes: number; customer_behavior_minutes: number; map_minutes: number };
    metrics: { current_visitors: Metric; visits: Metric; orders: Metric; total_sales: Metric };
    customer_behavior: { active_carts: Metric; checking_out: Metric; purchased: Metric };
    insights: { visits_by_location: LiveInsight; visits_by_source: LiveInsight; new_vs_returning: LiveInsight; sales_by_product: LiveInsight };
    traffic: { available: boolean; receiving: boolean; status: 'active' | 'idle' | 'not_received'; reason_code: string | null; message: string };
    shopify: { available: boolean; complete: boolean; source: string; date: string | null; fetched_at: string | null };
    locations: LiveLocation[];
    privacy: { location_precision: string; raw_ip_collected: boolean };
    generated_at: string;
}

const props = defineProps<{ snapshot: LiveSnapshot }>();
const live = ref(props.snapshot);
const mode = ref<'globe' | 'map'>('globe');
const layer = ref<'orders' | 'visitors'>('orders');
const search = ref('');
const isRefreshing = ref(false);
const mapReady = ref(false);
const mapError = ref<string | null>(null);
const mapContainer = ref<HTMLDivElement | null>(null);
const stageContainer = ref<HTMLElement | null>(null);
let map: MapLibreMap | null = null;
let refreshTimer: number | undefined;
let animationFrame: number | undefined;
let resumeSpinAt = 0;

const metricCards = computed(() => [
    { key: 'current_visitors', label: '当前访客', metric: live.value.metrics.current_visitors, currency: false },
    { key: 'total_sales', label: '总销售额', metric: live.value.metrics.total_sales, currency: true },
    { key: 'visits', label: '访问', metric: live.value.metrics.visits, currency: false },
    { key: 'orders', label: '订单', metric: live.value.metrics.orders, currency: false },
]);
const behaviorCards = computed(() => [
    { label: '活跃购物车', metric: live.value.customer_behavior.active_carts },
    { label: '正在结账', metric: live.value.customer_behavior.checking_out },
    { label: '已购买', metric: live.value.customer_behavior.purchased },
]);
const insightCards = computed(() => [
    { key: 'visits_by_location', title: '按地点划分的访问量', insight: live.value.insights.visits_by_location },
    { key: 'visits_by_source', title: '访问来源', insight: live.value.insights.visits_by_source },
    { key: 'new_vs_returning', title: '新客户与回头客', insight: live.value.insights.new_vs_returning },
    { key: 'sales_by_product', title: '按产品统计的总销售额', insight: live.value.insights.sales_by_product },
]);
const filteredLocations = computed(() => {
    const keyword = search.value.trim().toLocaleLowerCase('zh-CN');
    return keyword ? live.value.locations.filter((item) => item.label.toLocaleLowerCase('zh-CN').includes(keyword)) : live.value.locations;
});
const generatedTime = computed(() => new Intl.DateTimeFormat('zh-CN', {
    hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false, timeZone: live.value.store.timezone,
}).format(new Date(live.value.generated_at)));
const formatCurrency = (value: number) => new Intl.NumberFormat('zh-CN', {
    style: 'currency', currency: live.value.store.currency, maximumFractionDigits: 2,
}).format(value);
const sparkline = (values: number[]): string => {
    const max = Math.max(...values, 1);
    return values.map((value, index) => `${index ? 'L' : 'M'} ${(index / Math.max(values.length - 1, 1)) * 100} ${26 - (value / max) * 21}`).join(' ');
};
const mapPadding = (): PaddingOptions => ({ top: 0, right: 0, bottom: 0, left: window.innerWidth >= 1024 ? 370 : 0 });
const layerColor = () => layer.value === 'orders' ? '#8b5cf6' : '#0ea5e9';
const ensureReadableMapLabels = (instance: MapLibreMap) => {
    for (const styleLayer of instance.getStyle().layers ?? []) {
        if (styleLayer.type !== 'symbol') continue;
        const textSize = instance.getLayoutProperty(styleLayer.id, 'text-size');

        instance.setLayoutProperty(styleLayer.id, 'text-size', readableMapTextSize(textSize) as DataDrivenPropertyValueSpecification<number>);
    }
};

const refresh = async () => {
    if (isRefreshing.value) return;
    isRefreshing.value = true;
    try {
        const response = await fetch('/analytics/live/data', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (response.ok) live.value = await response.json() as LiveSnapshot;
        updateLocationLayer();
    } finally {
        isRefreshing.value = false;
    }
};
const locationGeoJson = (): FeatureCollection<Point> => ({
    type: 'FeatureCollection',
    features: filteredLocations.value.map((location) => ({
        type: 'Feature',
        geometry: { type: 'Point', coordinates: [location.longitude, location.latitude] },
        properties: { ...location, value: layer.value === 'orders' ? location.orders : location.visitors },
    })),
});
const updateLocationLayer = () => {
    if (!mapReady.value || !map) return;
    (map.getSource('live-locations') as GeoJSONSource | undefined)?.setData(locationGeoJson());
    ['live-location-glow', 'live-location-points'].forEach((id) => {
        if (map?.getLayer(id)) map.setPaintProperty(id, 'circle-color', layerColor());
    });
};
const pauseAutoSpin = () => { resumeSpinAt = performance.now() + 7_000; };
const animateMap = (timestamp: number) => {
    if (map && mapReady.value) {
        if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches && mode.value === 'globe' && timestamp > resumeSpinAt && map.getZoom() <= 2.6) {
            const center = map.getCenter();
            map.setCenter([center.lng + 0.012, center.lat]);
        }
        if (map.getLayer('live-location-glow')) {
            const pulse = (Math.sin(timestamp / 420) + 1) / 2;
            map.setPaintProperty('live-location-glow', 'circle-opacity', 0.08 + pulse * 0.14);
            map.setPaintProperty('live-location-glow', 'circle-radius', [
                'interpolate', ['linear'], ['get', 'value'], 0, 10 + pulse * 4, 1, 16 + pulse * 5, 20, 30 + pulse * 8,
            ]);
        }
    }
    animationFrame = requestAnimationFrame(animateMap);
};
const showPopup = (feature: Feature<Point>) => {
    const mapInstance = map;
    if (!mapInstance || !feature.properties) return;
    const content = document.createElement('div');
    const title = document.createElement('strong');
    const detail = document.createElement('span');
    title.textContent = String(feature.properties.label ?? '未知地点');
    detail.textContent = layer.value === 'orders' ? `${String(feature.properties.orders ?? 0)} 单` : `${String(feature.properties.visitors ?? 0)} 位访客`;
    content.className = 'live-popup';
    content.append(title, detail);
    new Popup({ offset: 16, closeButton: false }).setLngLat(feature.geometry.coordinates as [number, number]).setDOMContent(content).addTo(mapInstance);
};

const initializeMap = () => {
    if (!mapContainer.value || !stageContainer.value || map) return;
    try {
        const instance = new Map({
            container: mapContainer.value,
            style: 'https://tiles.openfreemap.org/styles/bright',
            center: [-22, 22], zoom: 1.45, minZoom: 0.65, maxZoom: 14,
            attributionControl: false,
            canvasContextAttributes: { antialias: true },
        });
        map = instance;
        instance.addControl(new AttributionControl({ compact: true }), 'bottom-left');
        instance.addControl(new NavigationControl({ showCompass: true, visualizePitch: true }), 'bottom-right');
        instance.addControl(new FullscreenControl({ container: stageContainer.value }), 'bottom-right');
        instance.on('style.load', () => {
            instance.setProjection({ type: 'globe' });
            ensureReadableMapLabels(instance);
        });
        instance.on('load', () => {
            instance.easeTo({ padding: mapPadding(), duration: 0 });
            instance.addSource('live-locations', { type: 'geojson', data: locationGeoJson() });
            instance.addLayer({
                id: 'live-location-glow', type: 'circle', source: 'live-locations',
                paint: { 'circle-radius': ['interpolate', ['linear'], ['get', 'value'], 0, 12, 1, 18, 20, 34], 'circle-color': layerColor(), 'circle-opacity': 0.16, 'circle-blur': 0.45 },
            });
            instance.addLayer({
                id: 'live-location-points', type: 'circle', source: 'live-locations',
                paint: { 'circle-radius': ['interpolate', ['linear'], ['get', 'value'], 0, 4.5, 1, 6.5, 20, 12], 'circle-color': layerColor(), 'circle-stroke-color': '#fff', 'circle-stroke-width': 2.5 },
            });
            instance.on('mouseenter', 'live-location-points', () => { instance.getCanvas().style.cursor = 'pointer'; });
            instance.on('mouseleave', 'live-location-points', () => { instance.getCanvas().style.cursor = ''; });
            instance.on('click', 'live-location-points', (event) => { if (event.features?.[0]) showPopup(event.features[0] as Feature<Point>); });
            mapReady.value = true;
            mapError.value = null;
        });
        instance.on('error', () => { if (!mapReady.value) mapError.value = '地图资源暂时加载失败，请检查网络后刷新。'; });
        ['mousedown', 'touchstart', 'wheel'].forEach((name) => mapContainer.value?.addEventListener(name, pauseAutoSpin, { passive: true }));
    } catch {
        mapError.value = '地图初始化失败，请刷新页面重试。';
    }
};
const retryMap = () => {
    mapError.value = null;
    mapReady.value = false;
    map?.remove();
    map = null;
    nextTick(initializeMap);
};
const setMode = (value: 'globe' | 'map') => {
    mode.value = value;
    pauseAutoSpin();
    map?.setProjection({ type: value === 'globe' ? 'globe' : 'mercator' });
    map?.easeTo({ center: value === 'globe' ? [-22, 22] : [0, 16], zoom: value === 'globe' ? 1.45 : 1.2, pitch: 0, bearing: 0, padding: mapPadding(), duration: 850 });
};
const focusSearch = () => {
    const keyword = search.value.trim().toLocaleLowerCase('zh-CN');
    const location = live.value.locations.find((item) => item.label.toLocaleLowerCase('zh-CN').includes(keyword));
    if (!keyword || !location || !map) return;
    pauseAutoSpin();
    map.flyTo({ center: [location.longitude, location.latitude], zoom: Math.max(map.getZoom(), 4), duration: 900 });
};
const handleResize = () => { map?.resize(); map?.easeTo({ padding: mapPadding(), duration: 0 }); };

onMounted(() => {
    nextTick(initializeMap);
    refreshTimer = window.setInterval(refresh, 30_000);
    animationFrame = requestAnimationFrame(animateMap);
    window.addEventListener('resize', handleResize);
});
onUnmounted(() => {
    if (refreshTimer) clearInterval(refreshTimer);
    if (animationFrame) cancelAnimationFrame(animationFrame);
    window.removeEventListener('resize', handleResize);
    map?.remove();
    map = null;
});
watch([layer, search], updateLocationLayer);
</script>

<template>
    <Head title="实时视图" />
    <AppLayout viewport :breadcrumbs="[{ label: '工作台', href: '/dashboard' }, { label: '数据分析' }, { label: '实时视图' }]">
        <section ref="stageContainer" class="live-stage">
            <div ref="mapContainer" class="live-map" aria-label="实时活动地图"></div>

            <div class="map-topbar">
                <label class="map-search">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input v-model="search" type="search" placeholder="搜索实时活动地点" @keyup.enter="focusSearch">
                </label>
                <div class="mode-switch">
                    <button type="button" :class="{ active: mode === 'globe' }" @click="setMode('globe')">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="12" cy="12" r="9"/><path d="M3.6 9h16.8M3.6 15h16.8M12 3c2.2 2.5 3.3 5.5 3.3 9S14.2 18.5 12 21M12 3C9.8 5.5 8.7 8.5 8.7 12S9.8 18.5 12 21"/></svg><span>地球</span>
                    </button>
                    <button type="button" :class="{ active: mode === 'map' }" @click="setMode('map')">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="m3 6 5-3 8 3 5-3v15l-5 3-8-3-5 3Z"/><path d="M8 3v15M16 6v15"/></svg><span>平面</span>
                    </button>
                </div>
            </div>

            <aside class="live-overlay">
                <header class="overlay-header">
                    <div>
                        <div class="live-status"><span></span>实时更新</div>
                        <h1>实时视图</h1>
                        <p>{{ live.store.name }} · {{ live.period.label }}（店铺时区）</p>
                    </div>
                    <button type="button" :disabled="isRefreshing" class="refresh-button" @click="refresh">
                        <svg :class="{ spinning: isRefreshing }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 4v7h-7"/></svg><span>{{ generatedTime }}</span>
                    </button>
                </header>

                <div class="metric-grid">
                    <article v-for="item in metricCards" :key="item.key" class="metric-card">
                        <div class="metric-label"><span>{{ item.label }}</span><em v-if="!item.metric.available">未接入</em></div>
                        <strong>{{ item.metric.available ? (item.currency ? formatCurrency(item.metric.value) : item.metric.value) : '—' }}</strong>
                        <svg v-if="item.metric.trend.length" viewBox="0 0 100 28" preserveAspectRatio="none"><path :d="sparkline(item.metric.trend)" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg>
                        <small v-else>{{ item.metric.message }}</small>
                    </article>
                </div>

                <section class="overlay-section">
                    <div class="section-heading">
                        <div><span>客户行为</span><small>最近 {{ live.period.customer_behavior_minutes }} 分钟</small></div>
                        <span class="connection-pill" :class="{ connected: live.traffic.receiving }">{{ live.traffic.status === 'active' ? 'Web Pixel 正在接收' : (live.traffic.status === 'idle' ? 'Web Pixel 近期无事件' : 'Web Pixel 尚无事件') }}</span>
                    </div>
                    <div class="behavior-row">
                        <div v-for="item in behaviorCards" :key="item.label"><strong>{{ item.metric.available ? item.metric.value : '—' }}</strong><span>{{ item.label }}</span></div>
                    </div>
                </section>

                <section v-for="item in insightCards" :key="item.key" class="insight-card">
                    <header>
                        <h2>{{ item.title }}</h2>
                        <span v-if="item.insight.classification === 'shopifyql'">Shopify Analytics</span>
                        <span v-else-if="item.insight.classification === 'web_pixel'">Web Pixel</span>
                        <span v-else-if="!item.insight.available">暂无数据</span>
                    </header>
                    <div v-if="item.insight.items.length" class="insight-list">
                        <div v-for="row in item.insight.items" :key="row.label">
                            <span>{{ row.label }}</span>
                            <strong>{{ item.key === 'sales_by_product' ? formatCurrency(row.value) : row.value }}</strong>
                        </div>
                    </div>
                    <div v-else class="insight-empty">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 19V9M10 19V5M16 19v-7M22 19H2"/></svg>
                        <p>{{ item.insight.message }}</p>
                    </div>
                </section>

                <section class="overlay-section location-section">
                    <div class="section-heading">
                        <div><span>实时活动地点</span><small>最近 {{ live.period.map_minutes }} 分钟 · 城市级模糊位置</small></div>
                        <div class="layer-switch">
                            <button type="button" :class="{ active: layer === 'orders' }" @click="layer = 'orders'">订单</button>
                            <button type="button" :class="{ active: layer === 'visitors' }" @click="layer = 'visitors'">访客</button>
                        </div>
                    </div>
                    <div v-if="filteredLocations.length" class="location-list">
                        <button v-for="location in filteredLocations" :key="location.id" type="button" @click="search = location.label; focusSearch()">
                            <span><i :class="layer"></i>{{ location.label }}</span><strong>{{ layer === 'orders' ? `${location.orders} 单` : `${location.visitors} 人` }}</strong>
                        </button>
                    </div>
                    <div v-else class="empty-location">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>
                        <p>{{ live.traffic.message }}</p>
                    </div>
                </section>
                <footer class="privacy-note">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>仅保留粗粒度位置，不记录访客原始 IP
                </footer>
            </aside>

            <div class="map-legend">
                <span><i :class="layer"></i>{{ layer === 'orders' ? '实时订单' : '当前访客' }}</span>
                <small>拖动{{ mode === 'globe' ? '旋转地球' : '平移地图' }} · 滚轮缩放</small>
            </div>
            <div v-if="!mapReady && !mapError" class="map-state"><div><span></span>正在加载实时地图…</div></div>
            <div v-if="mapError" class="map-state map-state--error"><div><strong>地图暂时不可用</strong><p>{{ mapError }}</p><button type="button" @click="retryMap">重新加载</button></div></div>
        </section>
    </AppLayout>
</template>

<style scoped>
.live-stage{position:relative;height:100%;min-height:0;overflow:hidden;isolation:isolate;border:1px solid rgb(203 213 225/.75);border-radius:2rem;background:#dceff1;box-shadow:0 24px 70px rgb(15 23 42/.12)}
.live-stage:fullscreen,.live-stage.maplibregl-map-fullscreen{width:100vw!important;height:100vh!important;max-height:none;border:0;border-radius:0}.live-map{position:absolute;inset:0}.live-map:after{position:absolute;inset:0;pointer-events:none;content:'';background:linear-gradient(90deg,rgb(15 23 42/.1),transparent 42%),linear-gradient(0deg,rgb(15 23 42/.08),transparent 30%)}
.map-topbar{position:absolute;z-index:20;top:1.25rem;right:1.25rem;left:390px;display:flex;justify-content:flex-end;gap:.75rem;pointer-events:none}.map-search,.mode-switch{pointer-events:auto;border:1px solid rgb(255 255 255/.72);background:rgb(255 255 255/.9);box-shadow:0 12px 34px rgb(15 23 42/.14);backdrop-filter:blur(18px)}.map-search{position:relative;width:min(360px,45%);border-radius:1rem}.map-search svg{position:absolute;top:50%;left:1rem;width:1.1rem;height:1.1rem;transform:translateY(-50%);color:#64748b}.map-search input{width:100%;border:0;border-radius:inherit;background:transparent;padding:.85rem 1rem .85rem 2.8rem;color:#0f172a;font-size: 0.75rem;outline:none}
.mode-switch{display:flex;padding:.25rem;border-radius:1rem}.mode-switch button{display:flex;align-items:center;gap:.4rem;border-radius:.75rem;padding:.6rem .75rem;color:#64748b;font-size: 0.75rem;font-weight:700;transition:160ms ease}.mode-switch button.active{background:#0f172a;color:#fff;box-shadow:0 6px 14px rgb(15 23 42/.2)}.mode-switch svg{width:1.05rem;height:1.05rem}
.live-overlay{position:absolute;z-index:25;top:1rem;bottom:1rem;left:1rem;display:flex;width:350px;flex-direction:column;overflow-x:hidden;overflow-y:auto;overscroll-behavior:contain;scrollbar-width:thin;scrollbar-color:rgb(148 163 184/.55) transparent;border:1px solid rgb(255 255 255/.78);border-radius:1.6rem;background:rgb(255 255 255/.91);box-shadow:0 24px 60px rgb(15 23 42/.2);backdrop-filter:blur(22px) saturate(135%)}.live-overlay::-webkit-scrollbar{width:6px}.live-overlay::-webkit-scrollbar-track{background:transparent}.live-overlay::-webkit-scrollbar-thumb{border-radius:999px;background:rgb(148 163 184/.55)}.overlay-header{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;padding:1.25rem 1.25rem 1rem}.live-status{display:flex;align-items:center;gap:.45rem;color:#047857;font-size: 0.75rem;font-weight:800;letter-spacing:.13em}.live-status span{width:.48rem;height:.48rem;border-radius:999px;background:#10b981;box-shadow:0 0 0 5px rgb(16 185 129/.13);animation:pulse 2s ease-in-out infinite}.overlay-header h1{margin-top:.55rem;color:#0f172a;font-size:1.24rem;font-weight:750;letter-spacing:-.035em}.overlay-header p{margin-top:.25rem;color:#64748b;font-size: 0.75rem}.refresh-button{display:flex;align-items:center;gap:.35rem;border:1px solid #e2e8f0;border-radius:.75rem;background:rgb(248 250 252/.9);padding:.52rem .62rem;color:#475569;font-size: 0.75rem;font-weight:700}.refresh-button svg{width:.85rem;height:.85rem}.refresh-button svg.spinning{animation:spin 1s linear infinite}
.metric-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.65rem;padding:0 1rem 1rem}.metric-card{min-height:112px;border:1px solid rgb(226 232 240/.85);border-radius:1.1rem;background:rgb(248 250 252/.78);padding:.85rem}.metric-label{display:flex;align-items:center;justify-content:space-between;gap:.4rem;color:#64748b;font-size: 0.75rem;font-weight:650}.metric-label em{border-radius:999px;background:#fef3c7;padding:.15rem .38rem;color:#a16207;font-size: 0.75rem;font-style:normal}.metric-card strong{display:block;margin-top:.55rem;overflow:hidden;color:#0f172a;font-size:1.08rem;font-weight:760;letter-spacing:-.035em;text-overflow:ellipsis;white-space:nowrap}.metric-card svg{width:100%;height:1.5rem;margin-top:.4rem;color:#10b981}.metric-card small{display:-webkit-box;margin-top:.45rem;overflow:hidden;color:#94a3b8;font-size: 0.75rem;line-height:1.35;-webkit-box-orient:vertical;-webkit-line-clamp:2}
.overlay-section{margin:0 1rem .75rem;border:1px solid rgb(226 232 240/.82);border-radius:1.1rem;background:rgb(255 255 255/.7);padding:.9rem}.section-heading{display:flex;align-items:center;justify-content:space-between;gap:.75rem}.section-heading>div:first-child{display:flex;flex-direction:column}.section-heading span{color:#1e293b;font-size: 0.75rem;font-weight:750}.section-heading small{margin-top:.1rem;color:#94a3b8;font-size: 0.75rem}.connection-pill{border-radius:999px;background:#f1f5f9;padding:.3rem .55rem;color:#64748b!important;font-size: 0.75rem!important}.connection-pill.connected{background:#d1fae5;color:#047857!important}.behavior-row{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));margin-top:.75rem}.behavior-row div{text-align:center}.behavior-row div+div{border-left:1px solid #e2e8f0}.behavior-row strong,.behavior-row span{display:block}.behavior-row strong{color:#0f172a;font-size:.84rem}.behavior-row span{margin-top:.18rem;color:#64748b;font-size: 0.75rem}
.insight-card{flex:none;min-height:150px;margin:0 1rem .75rem;border:1px solid rgb(226 232 240/.82);border-radius:1.1rem;background:rgb(255 255 255/.76);padding:.95rem}.insight-card header{display:flex;align-items:center;justify-content:space-between;gap:.75rem}.insight-card h2{color:#1e293b;font-size: 0.75rem;font-weight:750}.insight-card header>span{border-radius:999px;background:#f1f5f9;padding:.25rem .5rem;color:#94a3b8;font-size: 0.75rem;font-weight:700}.insight-empty{display:grid;min-height:100px;place-items:center;align-content:center;gap:.45rem;color:#94a3b8;text-align:center}.insight-empty svg{width:1.35rem;height:1.35rem}.insight-empty p{font-size: 0.75rem}.insight-list{margin-top:.7rem}.insight-list div{display:flex;align-items:center;justify-content:space-between;border-top:1px solid #f1f5f9;padding:.55rem .1rem;color:#64748b;font-size: 0.75rem}.insight-list strong{color:#0f172a}
.location-section{flex:none;overflow:hidden}.layer-switch{display:flex!important;flex-direction:row!important;border-radius:.6rem;background:#f1f5f9;padding:.18rem}.layer-switch button{border-radius:.45rem;padding:.28rem .48rem;color:#64748b;font-size: 0.75rem;font-weight:700}.layer-switch button.active{background:#fff;color:#0f172a;box-shadow:0 2px 6px rgb(15 23 42/.1)}.location-list{max-height:180px;margin-top:.65rem;overflow-y:auto}.location-list button{display:flex;width:100%;align-items:center;justify-content:space-between;gap:1rem;border-top:1px solid #f1f5f9;padding:.62rem .2rem;color:#475569;font-size: 0.75rem;text-align:left}.location-list button span{display:flex;align-items:center;gap:.45rem}.location-list strong{color:#0f172a}.location-list i,.map-legend i{width:.45rem;height:.45rem;border-radius:999px;background:#8b5cf6;box-shadow:0 0 0 4px rgb(139 92 246/.14)}.location-list i.visitors,.map-legend i.visitors{background:#0ea5e9;box-shadow:0 0 0 4px rgb(14 165 233/.14)}
.empty-location{display:grid;min-height:105px;place-items:center;padding:.75rem;color:#94a3b8;text-align:center}.empty-location svg{width:1.5rem;height:1.5rem}.empty-location p{max-width:230px;margin-top:.4rem;font-size: 0.75rem;line-height:1.5}.privacy-note{display:flex;align-items:center;gap:.4rem;padding:0 1.25rem 1rem;color:#94a3b8;font-size: 0.75rem}.privacy-note svg{width:.8rem;height:.8rem}
.map-legend{position:absolute;z-index:15;right:5rem;bottom:1.25rem;display:flex;align-items:center;gap:.7rem;border:1px solid rgb(255 255 255/.72);border-radius:.9rem;background:rgb(255 255 255/.88);padding:.65rem .85rem;box-shadow:0 10px 28px rgb(15 23 42/.14);backdrop-filter:blur(16px)}.map-legend span{display:flex;align-items:center;gap:.45rem;color:#334155;font-size: 0.75rem;font-weight:750}.map-legend small{border-left:1px solid #e2e8f0;padding-left:.7rem;color:#64748b;font-size: 0.75rem}
.map-state{position:absolute;z-index:40;inset:0;display:grid;place-items:center;background:#dceff1}.map-state>div{border:1px solid rgb(255 255 255/.8);border-radius:1rem;background:rgb(255 255 255/.9);padding:.8rem 1rem;color:#475569;font-size: 0.75rem;font-weight:700;box-shadow:0 12px 35px rgb(15 23 42/.12)}.map-state span{display:inline-block;width:.55rem;height:.55rem;margin-right:.5rem;border-radius:999px;background:#10b981;animation:pulse 1.6s ease-in-out infinite}.map-state--error>div{max-width:360px;padding:1.4rem;text-align:center}.map-state--error strong{color:#0f172a}.map-state--error p{margin-top:.45rem;color:#64748b;font-size: 0.75rem;font-weight:400}.map-state--error button{margin-top:.9rem;border-radius:.7rem;background:#0f172a;padding:.55rem .8rem;color:#fff;font-size: 0.75rem}
:deep(.maplibregl-ctrl-bottom-right){right:.8rem;bottom:.8rem}:deep(.maplibregl-ctrl-group){overflow:hidden;border:1px solid rgb(255 255 255/.8);border-radius:.85rem;background:rgb(255 255 255/.9);box-shadow:0 10px 28px rgb(15 23 42/.14);backdrop-filter:blur(16px)}:deep(.maplibregl-ctrl-group button){width:36px;height:36px}:deep(.maplibregl-popup-content){border-radius:.8rem;padding:.8rem .9rem;box-shadow:0 12px 35px rgb(15 23 42/.18)}:deep(.live-popup){display:grid;gap:.2rem;color:#0f172a;font-size: 0.75rem}:deep(.live-popup span){color:#64748b}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.55;transform:scale(.78)}}@keyframes spin{to{transform:rotate(360deg)}}
@media(max-width:1023px){.map-topbar{left:1rem}.live-overlay{top:auto;right:1rem;width:auto;max-height:48%}.metric-grid{grid-template-columns:repeat(4,minmax(120px,1fr));overflow-x:auto}.metric-card{min-height:92px}.metric-card small,.metric-card svg,.insight-card,.location-section,.privacy-note,.map-legend{display:none}}
@media(max-width:640px){.live-stage{border-radius:1.3rem}.map-topbar{top:.75rem;right:.75rem;left:.75rem}.map-search{width:auto;flex:1}.mode-switch span{display:none}.live-overlay{right:.75rem;bottom:.75rem;left:.75rem;max-height:51%;border-radius:1.25rem}.overlay-header h1{font-size:1rem}.refresh-button span{display:none}.metric-grid{gap:.45rem;padding-right:.75rem;padding-left:.75rem}.metric-card{min-height:80px;padding:.7rem}.metric-card strong{font-size:.864rem}.overlay-section{margin-right:.75rem;margin-left:.75rem}}
@media(prefers-reduced-motion:reduce){.live-status span,.map-state span{animation:none}}
</style>
