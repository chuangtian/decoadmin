<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{ links: Array<{ url: string | null; label: string; active: boolean }> }>();
</script>

<template>
    <nav v-if="links.length > 3" class="mt-6 flex flex-wrap gap-2" aria-label="分页">
        <Link
            v-for="link in links"
            :key="link.label"
            :href="link.url ?? ''"
            class="rounded-lg border px-3 py-2 text-sm transition"
            :class="link.active ? 'border-slate-950 bg-slate-950 text-white' : link.url ? 'border-slate-200 bg-white text-slate-600 hover:border-emerald-300 hover:text-emerald-700' : 'pointer-events-none border-slate-100 bg-slate-50 text-slate-300'"
            v-html="link.label"
        />
    </nav>
</template>
