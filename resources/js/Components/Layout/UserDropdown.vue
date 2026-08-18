<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import type { SharedProps } from '../../types';
import UserAvatar from '../Users/UserAvatar.vue';

const page = usePage<SharedProps>();
</script>

<template>
    <details class="group relative">
        <summary class="flex cursor-pointer list-none items-center gap-3 rounded-xl px-2 py-1.5 transition hover:bg-slate-100">
            <UserAvatar :name="page.props.auth.user?.name" :url="page.props.auth.user?.avatar_url" size="md" />
            <span class="hidden min-w-0 text-left sm:block"><span class="block max-w-36 truncate text-sm font-semibold text-slate-800">{{ page.props.auth.user?.name }}</span><span class="block max-w-36 truncate text-xs text-slate-500">{{ page.props.auth.user?.email }}</span></span>
            <svg class="hidden h-4 w-4 text-slate-400 transition group-open:rotate-180 sm:block" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
        </summary>
        <div class="absolute right-0 z-30 mt-2 w-64 overflow-hidden rounded-2xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-900/10">
            <div class="border-b border-slate-100 px-3 py-2.5"><p class="truncate text-sm font-semibold text-slate-900">{{ page.props.auth.user?.name }}</p><p class="truncate text-xs text-slate-500">{{ page.props.auth.user?.email }}</p></div>
            <Link href="/logout" method="post" as="button" class="mt-2 flex w-full items-center gap-2 rounded-xl px-3 py-2.5 text-left text-sm font-medium text-rose-600 hover:bg-rose-50">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 17l5-5-5-5M15 12H3"/><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/></svg>退出登录
            </Link>
        </div>
    </details>
</template>
