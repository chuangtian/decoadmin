<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { ApiError, createApiClient } from './api';
import GalleryEditor from './GalleryEditor.vue';
import Overview from './Overview.vue';
import type { Overview as OverviewData } from './types';

const props = defineProps<{ apiBase: string; environment: string }>();

const api = createApiClient(props.apiBase);

const overview = ref<OverviewData | null>(null);
const fatal = ref<string | null>(null);
const loading = ref(true);
const busy = ref(false);
const openGalleryId = ref<string | null>(null);
const notice = ref<{ tone: 'success' | 'error'; text: string } | null>(null);

let noticeTimer: number | undefined;
const flash = (tone: 'success' | 'error', text: string) => {
    notice.value = { tone, text };
    window.clearTimeout(noticeTimer);
    // 成功提示自动收起，失败留在页面上让商家看清原因。
    if (tone === 'success') {
        noticeTimer = window.setTimeout(() => { notice.value = null; }, 6000);
    }
};

const describe = (error: unknown) => (error instanceof ApiError ? error.message : '操作失败，请稍后重试。');

const loadOverview = async () => {
    try {
        overview.value = await api.get<OverviewData>('/overview');
        fatal.value = null;
    } catch (error) {
        // 概览拉不到就等于整页不可用，单独用 fatal 展示，不混进操作提示里。
        fatal.value = describe(error);
    } finally {
        loading.value = false;
    }
};

const retry = () => {
    loading.value = true;
    void loadOverview();
};

/**
 * 所有写操作的统一外壳：跑动作、提示结果、按需刷新。
 *
 * 后端的写接口只回消息不回视图数据，刷新什么由调用方决定。
 */
const run = async (action: () => Promise<{ message: string }>, refresh?: () => Promise<void>) => {
    if (busy.value) return;
    busy.value = true;
    try {
        const result = await action();
        flash('success', result.message);
        await refresh?.();
    } catch (error) {
        flash('error', describe(error));
    } finally {
        busy.value = false;
    }
};

const openGallery = (id: string) => { openGalleryId.value = id; };
const closeGallery = () => {
    openGalleryId.value = null;
    void loadOverview();
};

// Meta 授权在新窗口完成，回调页 postMessage 回来后刷新账号状态。
const onAuthMessage = (event: MessageEvent) => {
    if (event.origin !== window.location.origin) return;
    if (event.data?.type !== 'instagram-feed-auth' || !event.data.ok) return;
    void loadOverview();
};

onMounted(() => {
    window.addEventListener('message', onAuthMessage);
    void loadOverview();
});
</script>

<template>
    <div class="min-h-screen bg-slate-50 p-4 sm:p-6">
        <div class="mx-auto max-w-6xl space-y-5">
            <div
                v-if="notice"
                class="rounded-xl border p-4 text-sm"
                :class="notice.tone === 'success'
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                    : 'border-rose-200 bg-rose-50 text-rose-800'"
                role="status"
                aria-live="polite"
            >
                {{ notice.text }}
            </div>

            <p v-if="loading" class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
                正在加载店铺内容…
            </p>

            <div v-else-if="fatal" class="rounded-2xl border border-rose-200 bg-white p-6">
                <h1 class="text-lg font-semibold text-slate-950">暂时无法打开</h1>
                <p class="mt-2 text-sm text-slate-600">{{ fatal }}</p>
                <button
                    type="button"
                    class="mt-4 rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800"
                    @click="retry"
                >
                    重试
                </button>
            </div>

            <GalleryEditor
                v-else-if="overview && openGalleryId"
                :api="api"
                :gallery-id="openGalleryId"
                :busy="busy"
                :capabilities="overview.capabilities"
                :run="run"
                @back="closeGallery"
            />

            <Overview
                v-else-if="overview"
                :api="api"
                :overview="overview"
                :busy="busy"
                :run="run"
                :refresh="loadOverview"
                @open-gallery="openGallery"
            />
        </div>
    </div>
</template>
