<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { onMounted, watch } from 'vue';
import { useToast } from '../../composables/useToast';
import type { FlashMessages, SharedProps, ToastType } from '../../types';
import ToastNotification from './ToastNotification.vue';

const page = usePage<SharedProps>();
const { toasts, add, dismiss } = useToast();
let lastFlashSignature = '';

const syncFlash = (flash: FlashMessages) => {
    const entries = (['success', 'error', 'warning', 'info'] as ToastType[])
        .map((type) => [type, flash[type]] as const)
        .filter((entry): entry is readonly [ToastType, string] => Boolean(entry[1]));
    const signature = JSON.stringify(entries);

    if (!entries.length) {
        lastFlashSignature = '';
        return;
    }

    if (signature === lastFlashSignature) return;
    lastFlashSignature = signature;
    entries.forEach(([type, message]) => add({ type, message }));
};

onMounted(() => syncFlash(page.props.flash));
watch(() => page.props.flash, syncFlash, { deep: true });
</script>

<template>
    <div class="pointer-events-none fixed inset-x-4 top-4 z-[80] ml-auto flex max-w-sm flex-col gap-3 sm:inset-x-auto sm:right-5 sm:top-5 sm:w-full" aria-live="polite" aria-atomic="true">
        <TransitionGroup enter-active-class="transition duration-200 ease-out" enter-from-class="translate-y-2 opacity-0 sm:translate-x-3 sm:translate-y-0" leave-active-class="transition duration-150 ease-in" leave-to-class="translate-y-2 opacity-0 sm:translate-x-3 sm:translate-y-0">
            <ToastNotification v-for="toast in toasts" :key="toast.id" :toast="toast" @dismiss="dismiss(toast.id)" />
        </TransitionGroup>
    </div>
</template>
