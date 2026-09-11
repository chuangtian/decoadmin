<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { SharedProps } from '../../types';

interface NotificationItem {
    uuid: string;
    type: string;
    title: string;
    message: string;
    action_url: string;
    read_at: string | null;
    created_at: string;
}

interface NotificationResponse {
    data: {
        unread_count: number;
        notifications: NotificationItem[];
    };
}

const page = usePage<SharedProps>();
const wrapper = ref<HTMLElement | null>(null);
const open = ref(false);
const loading = ref(false);
const unreadCount = ref(0);
const notifications = ref<NotificationItem[]>([]);
const unavailable = ref(false);
let timer: number | undefined;
let echo: Echo<'reverb'> | null = null;
let unsubscribeInertia: (() => void) | undefined;

const organizationId = computed(() => page.props.currentOrganization?.id ?? null);
const userId = computed(() => page.props.auth.user?.id ?? null);
const badge = computed(() => unreadCount.value > 99 ? '99+' : String(unreadCount.value));

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

async function loadNotifications(silent = false): Promise<void> {
    if (!organizationId.value || !userId.value || document.hidden) return;
    if (!silent) loading.value = true;

    try {
        const response = await fetch('/business-notifications', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
        });
        if (!response.ok) throw new Error(`Notification request failed: ${response.status}`);
        const payload = await response.json() as NotificationResponse;
        unreadCount.value = payload.data.unread_count;
        notifications.value = payload.data.notifications;
        unavailable.value = false;
    } catch {
        unavailable.value = true;
    } finally {
        loading.value = false;
    }
}

async function patchNotification(path: string): Promise<boolean> {
    const response = await fetch(path, {
        method: 'PATCH',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        body: '{}',
    });

    return response.ok;
}

async function markAllRead(): Promise<void> {
    if (unreadCount.value === 0) return;
    if (await patchNotification('/business-notifications/read-all')) {
        unreadCount.value = 0;
        notifications.value = notifications.value.map((item) => ({ ...item, read_at: item.read_at ?? new Date().toISOString() }));
    }
}

async function openNotification(item: NotificationItem): Promise<void> {
    if (!item.read_at && await patchNotification(`/business-notifications/${encodeURIComponent(item.uuid)}/read`)) {
        unreadCount.value = Math.max(0, unreadCount.value - 1);
        item.read_at = new Date().toISOString();
    }
    open.value = false;
    router.visit(item.action_url);
}

function relativeTime(value: string): string {
    const timestamp = new Date(value).getTime();
    const seconds = Math.max(0, Math.floor((Date.now() - timestamp) / 1000));
    if (seconds < 60) return '刚刚';
    if (seconds < 3600) return `${Math.floor(seconds / 60)} 分钟前`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)} 小时前`;
    if (seconds < 604800) return `${Math.floor(seconds / 86400)} 天前`;
    return new Intl.DateTimeFormat('zh-CN', { month: 'numeric', day: 'numeric' }).format(new Date(value));
}

function disconnectRealtime(): void {
    if (echo && organizationId.value && userId.value) {
        echo.leave(`business-notifications.${organizationId.value}.${userId.value}`);
    }
    echo?.disconnect();
    echo = null;
}

function connectRealtime(): void {
    disconnectRealtime();
    if (!organizationId.value || !userId.value || !page.props.realtime.enabled || !page.props.realtime.key) return;

    window.Pusher = Pusher;
    echo = new Echo({
        broadcaster: 'reverb',
        key: page.props.realtime.key,
        wsHost: window.location.hostname,
        wsPort: window.location.protocol === 'https:' ? 443 : Number(window.location.port || 80),
        wssPort: 443,
        forceTLS: window.location.protocol === 'https:',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
        auth: { headers: { 'X-CSRF-TOKEN': csrfToken() } },
    });
    echo.private(`business-notifications.${organizationId.value}.${userId.value}`)
        .listen('.business-notification.created', () => loadNotifications(true));
}

function handleOutside(event: MouseEvent): void {
    if (open.value && wrapper.value && !wrapper.value.contains(event.target as Node)) open.value = false;
}

function handleVisibility(): void {
    if (!document.hidden) loadNotifications(true);
}

watch([organizationId, userId], async () => {
    open.value = false;
    await nextTick();
    await loadNotifications();
    connectRealtime();
});

onMounted(() => {
    loadNotifications();
    connectRealtime();
    timer = window.setInterval(() => loadNotifications(true), 60_000);
    document.addEventListener('mousedown', handleOutside);
    document.addEventListener('visibilitychange', handleVisibility);
    unsubscribeInertia = router.on('finish', () => loadNotifications(true));
});

onBeforeUnmount(() => {
    if (timer) window.clearInterval(timer);
    unsubscribeInertia?.();
    document.removeEventListener('mousedown', handleOutside);
    document.removeEventListener('visibilitychange', handleVisibility);
    disconnectRealtime();
});
</script>

<template>
    <div ref="wrapper" class="relative shrink-0">
        <button
            type="button"
            class="relative grid h-11 w-11 place-items-center rounded-2xl border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
            :aria-expanded="open"
            aria-label="打开业务通知"
            @click="open = !open; open && loadNotifications()"
        >
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 17H5.8a1.8 1.8 0 0 1-1.5-2.8l.7-1.1V9a7 7 0 0 1 14 0v4.1l.7 1.1a1.8 1.8 0 0 1-1.5 2.8H15Zm0 0a3 3 0 0 1-6 0"/>
            </svg>
            <span v-if="unreadCount" class="absolute -right-1.5 -top-1.5 min-w-5 rounded-full border-2 border-white bg-rose-500 px-1 text-center text-xs font-bold leading-4 text-white">{{ badge }}</span>
        </button>

        <div v-if="open" class="absolute right-0 top-[calc(100%+0.75rem)] z-50 w-[min(25rem,calc(100vw-1.5rem))] overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-2xl shadow-slate-900/15">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                <div>
                    <h2 class="text-base font-bold text-slate-950">通知</h2>
                    <p class="mt-0.5 text-xs text-slate-500">{{ unreadCount ? `${unreadCount} 条未读` : '暂无未读通知' }}</p>
                </div>
                <button v-if="unreadCount" type="button" class="text-sm font-semibold text-emerald-700 hover:text-emerald-800" @click="markAllRead">全部已读</button>
            </div>

            <div class="max-h-[min(32rem,70vh)] overflow-y-auto">
                <div v-if="loading && notifications.length === 0" class="px-5 py-12 text-center text-sm text-slate-500">正在加载通知…</div>
                <div v-else-if="unavailable && notifications.length === 0" class="px-5 py-12 text-center">
                    <p class="text-sm font-semibold text-slate-700">暂时无法获取通知</p>
                    <button type="button" class="mt-3 text-sm font-semibold text-emerald-700" @click="loadNotifications()">重新加载</button>
                </div>
                <div v-else-if="notifications.length === 0" class="px-5 py-12 text-center">
                    <span class="mx-auto grid h-11 w-11 place-items-center rounded-2xl bg-slate-100 text-slate-400">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h8M12 8v8M5 19h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2Z"/></svg>
                    </span>
                    <p class="mt-3 text-sm font-semibold text-slate-700">还没有业务通知</p>
                    <p class="mt-1 text-xs text-slate-500">需求、审批和付款进度会显示在这里。</p>
                </div>
                <button
                    v-for="item in notifications"
                    :key="item.uuid"
                    type="button"
                    class="group flex w-full gap-3 border-b border-slate-100 px-5 py-4 text-left transition last:border-b-0 hover:bg-slate-50"
                    @click="openNotification(item)"
                >
                    <span class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full" :class="item.read_at ? 'bg-slate-200' : 'bg-emerald-500 ring-4 ring-emerald-50'" />
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-bold text-slate-900">{{ item.title }}</span>
                        <span class="mt-1 block text-sm leading-6 text-slate-600">{{ item.message }}</span>
                        <span class="mt-1.5 block text-xs font-medium text-slate-400">{{ relativeTime(item.created_at) }}</span>
                    </span>
                    <svg class="mt-1 h-4 w-4 shrink-0 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6"/></svg>
                </button>
            </div>
        </div>
    </div>
</template>
