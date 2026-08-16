<script setup lang="ts">
import type { ToastMessage } from '../../composables/useToast';

defineProps<{ toast: ToastMessage }>();
const emit = defineEmits<{ dismiss: [] }>();

const styles = {
    success: { shell: 'border-emerald-200 bg-white', icon: 'bg-emerald-100 text-emerald-700', symbol: '✓' },
    error: { shell: 'border-rose-200 bg-white', icon: 'bg-rose-100 text-rose-700', symbol: '!' },
    warning: { shell: 'border-amber-200 bg-white', icon: 'bg-amber-100 text-amber-700', symbol: '!' },
    info: { shell: 'border-sky-200 bg-white', icon: 'bg-sky-100 text-sky-700', symbol: 'i' },
} as const;
</script>

<template>
    <article class="pointer-events-auto flex w-full items-start gap-3 rounded-2xl border p-4 shadow-xl shadow-slate-900/10" :class="styles[toast.type].shell" :role="toast.type === 'error' ? 'alert' : 'status'">
        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-xl text-sm font-bold" :class="styles[toast.type].icon">{{ styles[toast.type].symbol }}</span>
        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-slate-900">{{ toast.title }}</p>
            <p class="mt-1 text-sm leading-5 text-slate-600">{{ toast.message }}</p>
        </div>
        <button type="button" class="rounded-lg p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" aria-label="关闭通知" @click="emit('dismiss')">×</button>
    </article>
</template>
