<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    framework: string;
}>();
</script>

<template>
    <main class="grid min-h-screen place-items-center bg-slate-950 px-6 text-slate-100">
        <section class="max-w-2xl text-center">
            <p class="mb-4 text-sm font-semibold tracking-[0.3em] text-emerald-400">基础架构 01</p>
            <h1 class="text-4xl font-semibold tracking-tight sm:text-6xl">Shopify Commerce Hub</h1>
            <p class="mt-6 text-lg leading-8 text-slate-300">
                Laravel {{ framework }} · Vue 3 · Inertia 3 · Tailwind CSS 4 · Vite 8
            </p>
            <Link href="/login" class="mt-8 inline-flex rounded-xl bg-emerald-400 px-5 py-3 text-sm font-semibold text-slate-950 hover:bg-emerald-300">进入管理工作台</Link>
        </section>
    </main>
</template>
