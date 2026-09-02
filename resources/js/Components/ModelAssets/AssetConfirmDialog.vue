<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue';

const props = withDefaults(defineProps<{
    open: boolean;
    title: string;
    message: string;
    confirmLabel?: string;
    processing?: boolean;
}>(), {
    confirmLabel: '永久删除',
    processing: false,
});

const emit = defineEmits<{
    cancel: [];
    confirm: [];
}>();

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape' && props.open && !props.processing) emit('cancel');
};

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition duration-150 ease-out" enter-from-class="opacity-0" leave-active-class="transition duration-100 ease-in" leave-to-class="opacity-0">
            <div v-if="open" class="fixed inset-0 z-[120] grid place-items-center bg-slate-950/55 p-4 backdrop-blur-[2px]" @mousedown.self="!processing && emit('cancel')">
                <section role="alertdialog" aria-modal="true" :aria-label="title" class="w-full max-w-md rounded-3xl border border-white/70 bg-white p-6 shadow-2xl shadow-slate-950/25 sm:p-7">
                    <div class="flex items-start gap-4">
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-rose-50 text-rose-600">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 9v4m0 4h.01"/><path d="M10.3 4.1 2.6 17.5A1.7 1.7 0 0 0 4.1 20h15.8a1.7 1.7 0 0 0 1.5-2.5L13.7 4.1a2 2 0 0 0-3.4 0Z"/></svg>
                        </span>
                        <div class="min-w-0">
                            <h2 class="text-lg font-semibold text-slate-950">{{ title }}</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600">{{ message }}</p>
                        </div>
                    </div>
                    <div class="mt-7 flex justify-end gap-3">
                        <button type="button" :disabled="processing" class="h-11 rounded-xl border border-slate-200 bg-white px-5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-50" @click="emit('cancel')">取消</button>
                        <button type="button" :disabled="processing" class="h-11 min-w-28 rounded-xl bg-rose-600 px-5 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-wait disabled:opacity-60" @click="emit('confirm')">
                            {{ processing ? '删除中…' : confirmLabel }}
                        </button>
                    </div>
                </section>
            </div>
        </Transition>
    </Teleport>
</template>
