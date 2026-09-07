import { router } from '@inertiajs/vue3';
import { computed, onScopeDispose, ref, watch } from 'vue';

export interface ReportStorage {
    pending?: boolean;
    refreshing?: boolean;
}

type Reload = (options: { only: string[]; onFinish: () => void }) => void;

// Refresh only missing report data, with no overlapping requests or endless polling.
export function useDeferredReport(
    state: () => { key: string; pending: boolean },
    only: string[],
    reload: Reload = (options) => router.reload(options),
) {
    const attempts = ref(0);
    const inFlight = ref(false);
    let timer: ReturnType<typeof setTimeout> | null = null;
    let disposed = false;
    const browser = typeof window !== 'undefined';
    const maxAttempts = 20;
    const timedOut = computed(() => state().pending && attempts.value >= maxAttempts && !inFlight.value);
    const refreshing = computed(() => state().pending && !timedOut.value);

    function clear() {
        if (timer !== null) clearTimeout(timer);
        timer = null;
    }
    function schedule() {
        clear();
        if (!browser || disposed || inFlight.value || !state().pending || attempts.value >= maxAttempts) return;
        timer = setTimeout(() => {
            timer = null;
            if (disposed || !state().pending) return;
            attempts.value += 1;
            inFlight.value = true;
            reload({ only, onFinish: () => {
                inFlight.value = false;
                schedule();
            } });
        }, 3000);
    }
    function retry() {
        attempts.value = 0;
        schedule();
    }
    watch(() => [state().key, state().pending] as const, ([key, pending], previous) => {
        if (!previous || previous[0] !== key || !pending) attempts.value = 0;
        schedule();
    }, { immediate: true });
    onScopeDispose(() => { disposed = true; clear(); });

    return { refreshing, timedOut, retry };
}
