<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{ code: string; message: string }>();
</script>

<template>
    <Head title="无法连接 DecoAdmin" />
    <main class="flex min-h-screen items-center justify-center bg-slate-50 px-4 py-10">
        <section class="w-full max-w-lg rounded-3xl border border-rose-200 bg-white p-8 text-center shadow-xl shadow-slate-200/60">
            <span class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-full bg-rose-100 text-2xl text-rose-700">!</span>
            <p class="mt-5 text-xs font-semibold uppercase tracking-widest text-rose-600">{{ code }}</p>
            <h1 class="mt-2 text-2xl font-semibold text-slate-950">无法连接 DecoAdmin</h1>
            <p class="mt-3 text-sm leading-6 text-slate-600">{{ message }}</p>
            <Link href="/dashboard" class="mt-7 inline-flex rounded-xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white">返回管理后台</Link>
        </section>
    </main>
</template>
