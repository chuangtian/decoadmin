<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppIcon from '../Layout/AppIcon.vue';

withDefaults(defineProps<{
    title: string;
    description: string;
    icon?: string;
    actionLabel?: string;
    actionHref?: string;
}>(), {
    icon: 'status',
    actionLabel: undefined,
    actionHref: undefined,
});

const emit = defineEmits<{ action: [] }>();
</script>

<template>
    <section class="rounded-2xl border border-dashed border-slate-300 bg-white px-5 py-12 text-center sm:px-8 sm:py-16">
        <div class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100">
            <AppIcon :name="icon" :size="24" />
        </div>
        <h3 class="mt-5 text-lg font-semibold text-slate-950">{{ title }}</h3>
        <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-slate-500">{{ description }}</p>
        <div v-if="$slots.default" class="mx-auto mt-5 max-w-md text-left text-sm leading-6 text-slate-600">
            <slot />
        </div>
        <div v-if="actionLabel || $slots.action" class="mt-6">
            <slot name="action">
                <Link v-if="actionHref" :href="actionHref" class="inline-flex items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">
                    {{ actionLabel }}
                </Link>
                <button v-else type="button" class="inline-flex items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800" @click="emit('action')">
                    {{ actionLabel }}
                </button>
            </slot>
        </div>
    </section>
</template>
