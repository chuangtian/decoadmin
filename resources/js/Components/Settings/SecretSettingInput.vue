<script setup lang="ts">
import { computed, ref } from 'vue';
import { useToast } from '../../composables/useToast';

const props = withDefaults(defineProps<{
    modelValue: string;
    label: string;
    placeholder?: string;
    help?: string;
    error?: string;
    disabled?: boolean;
    configured?: boolean;
}>(), {
    placeholder: '',
    help: '',
    error: '',
    disabled: false,
    configured: false,
});

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const revealed = ref(false);
const toast = useToast();
const inputType = computed(() => revealed.value ? 'text' : 'password');

const copy = async () => {
    if (!props.modelValue) return;

    try {
        await navigator.clipboard.writeText(props.modelValue);
        toast.success(`${props.label}已复制。`);
    } catch {
        toast.error('浏览器未允许复制，请手动选择内容。');
    }
};
</script>

<template>
    <label class="block">
        <span class="flex items-center gap-2 text-sm font-semibold text-slate-800">
            {{ label }}
            <span v-if="configured" class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">已配置</span>
        </span>
        <span class="mt-2 flex overflow-hidden rounded-xl border border-slate-200 bg-white transition focus-within:border-emerald-500 focus-within:ring-2 focus-within:ring-emerald-100" :class="{ 'bg-slate-50 opacity-70': disabled }">
            <input
                :value="modelValue"
                :type="inputType"
                :placeholder="placeholder"
                :disabled="disabled"
                autocomplete="off"
                class="min-w-0 flex-1 border-0 bg-transparent px-3.5 py-2.5 font-mono text-sm text-slate-900 outline-none placeholder:font-sans placeholder:text-slate-400 disabled:cursor-not-allowed"
                @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
            />
            <span class="flex shrink-0 items-center gap-1 border-l border-slate-100 px-1.5">
                <button type="button" :disabled="!modelValue" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-30" :aria-label="revealed ? `隐藏${label}` : `显示${label}`" @click="revealed = !revealed">
                    <svg v-if="!revealed" class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg v-else class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 3 18 18"/><path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 4.2A10.7 10.7 0 0 1 12 4c6.5 0 10 8 10 8a18.6 18.6 0 0 1-2.2 3.2M6.6 6.6C3.5 8.5 2 12 2 12s3.5 8 10 8a10.7 10.7 0 0 0 4.1-.8"/></svg>
                </button>
                <button type="button" :disabled="!modelValue" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-30" :aria-label="`复制${label}`" @click="copy">
                    <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></svg>
                </button>
            </span>
        </span>
        <span v-if="error" class="mt-1.5 block text-xs text-rose-600">{{ error }}</span>
        <span v-else-if="help" class="mt-1.5 block text-xs leading-5 text-slate-400">{{ help }}</span>
    </label>
</template>
