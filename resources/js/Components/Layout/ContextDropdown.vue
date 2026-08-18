<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { SharedProps, StoreOption } from '../../types';

const page = usePage<SharedProps>();
const details = ref<HTMLDetailsElement | null>(null);
const switching = ref(false);

const availableStores = computed(() => page.props.availableOrganizations.flatMap((organization) => organization.stores));
const otherStores = computed(() => availableStores.value.filter((store) => store.id !== page.props.currentStore?.id));

const initials = (name?: string) => {
    if (!name) return '—';

    const words = name.trim().split(/\s+/);
    return words.length > 1
        ? words.slice(0, 2).map((word) => word.charAt(0)).join('').toUpperCase()
        : name.slice(0, 2).toUpperCase();
};

const switchStore = (store: StoreOption) => {
    if (store.id === page.props.currentStore?.id) {
        if (details.value) details.value.open = false;
        return;
    }

    switching.value = true;
    const redirectStoreDetail = /^\/stores\/\d+(?:\?.*)?$/.test(page.url);
    router.put('/context/store', { store_id: store.id }, {
        preserveScroll: true,
        preserveState: false,
        onSuccess: () => {
            if (redirectStoreDetail) router.visit(`/stores/${store.id}`);
        },
        onFinish: () => {
            switching.value = false;
            if (details.value) details.value.open = false;
        },
    });
};
</script>

<template>
    <details ref="details" class="group relative w-fit shrink-0">
        <summary class="flex h-11 w-fit max-w-64 cursor-pointer list-none items-center gap-2 rounded-xl border border-slate-200 bg-white px-2 shadow-sm transition hover:border-slate-300 hover:shadow-md">
            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-emerald-500 text-[10px] font-bold text-white">
                {{ initials(page.props.currentStore?.name) }}
            </span>
            <span class="hidden max-w-40 truncate text-sm font-semibold text-slate-800 sm:block">
                {{ page.props.currentStore?.name ?? '暂无可用店铺' }}
            </span>
            <svg class="h-4 w-4 shrink-0 text-slate-400 transition group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" /></svg>
        </summary>

        <div class="absolute right-0 z-40 mt-2 w-80 max-w-[calc(100vw-1.5rem)] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl shadow-slate-900/10">
            <div class="p-3">
                <p class="px-2 pb-2 text-xs font-semibold text-slate-500">当前店铺</p>
                <button v-if="page.props.currentStore" type="button" class="flex w-full items-center gap-3 rounded-xl bg-slate-100 px-3 py-2.5 text-left" @click="details && (details.open = false)">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-emerald-500 text-[10px] font-bold text-white">{{ initials(page.props.currentStore.name) }}</span>
                    <span class="min-w-0 flex-1 truncate text-sm font-semibold text-slate-900">{{ page.props.currentStore.name }}</span>
                    <span class="text-lg text-slate-700">✓</span>
                </button>
                <p v-else class="rounded-xl bg-slate-50 px-3 py-4 text-center text-sm text-slate-500">暂无可用店铺</p>
            </div>

            <div class="border-t border-slate-100 p-3">
                <p class="px-2 pb-2 text-xs font-semibold text-slate-500">可切换店铺</p>
                <div class="max-h-64 overflow-y-auto">
                    <button v-for="store in otherStores" :key="store.id" type="button" :disabled="switching" class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition hover:bg-slate-50 disabled:opacity-50" @click="switchStore(store)">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-cyan-100 text-[10px] font-bold text-cyan-700">{{ initials(store.name) }}</span>
                        <span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium text-slate-800">{{ store.name }}</span><span class="block text-xs text-slate-400">{{ store.status === 'active' ? '运行中' : '待连接' }}</span></span>
                    </button>
                    <p v-if="!otherStores.length" class="px-3 py-3 text-sm text-slate-400">暂无其他授权店铺</p>
                </div>
            </div>

            <div class="border-t border-slate-100 p-3">
                <div class="flex items-center gap-3 rounded-xl px-3 py-2">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-violet-100 text-xs font-bold text-violet-700">{{ initials(page.props.auth.user?.name) }}</span>
                    <span class="min-w-0"><span class="block truncate text-sm font-semibold text-slate-900">{{ page.props.auth.user?.name }}</span><span class="block truncate text-xs text-slate-500">{{ page.props.auth.user?.email }}</span></span>
                </div>
                <Link href="/logout" method="post" as="button" class="mt-1 flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                    <svg class="h-5 w-5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 17l5-5-5-5M15 12H3"/><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/></svg>
                    退出登录
                </Link>
            </div>
        </div>
    </details>
</template>
