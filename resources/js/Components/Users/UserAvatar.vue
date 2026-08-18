<script setup lang="ts">
import { computed, ref, watch } from 'vue';

const props = withDefaults(defineProps<{
    name?: string;
    url?: string | null;
    size?: 'sm' | 'md' | 'lg' | 'xl';
    tone?: 'slate' | 'emerald' | 'violet';
}>(), {
    name: '',
    url: null,
    size: 'md',
    tone: 'slate',
});

const imageFailed = ref(false);
const initials = computed(() => {
    const name = props.name.trim();
    if (!name) return '?';

    const words = name.split(/\s+/);
    return words.length > 1
        ? words.slice(0, 2).map((word) => word.charAt(0)).join('').toUpperCase()
        : name.slice(0, 2).toUpperCase();
});
const sizeClass = computed(() => ({
    sm: 'h-8 w-8 text-[10px]',
    md: 'h-9 w-9 text-xs',
    lg: 'h-14 w-14 text-base',
    xl: 'h-24 w-24 text-2xl',
})[props.size]);
const toneClass = computed(() => ({
    slate: 'bg-slate-900 text-white',
    emerald: 'bg-emerald-100 text-emerald-700',
    violet: 'bg-violet-100 text-violet-700',
})[props.tone]);

watch(() => props.url, () => {
    imageFailed.value = false;
});
</script>

<template>
    <span class="relative inline-grid shrink-0 place-items-center overflow-hidden rounded-2xl font-bold ring-1 ring-black/5" :class="[sizeClass, toneClass]">
        <img v-if="url && !imageFailed" :src="url" :alt="`${name}的头像`" class="h-full w-full object-cover" @error="imageFailed = true" />
        <span v-else>{{ initials }}</span>
    </span>
</template>
