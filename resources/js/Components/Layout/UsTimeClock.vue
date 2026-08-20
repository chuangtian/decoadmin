<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { SharedProps } from '../../types';

const currentTime = ref('');
const page = usePage<SharedProps>();
const timezone = computed(() => page.props.currentStore?.timezone || 'UTC');
let timer: number | undefined;

const updateTime = () => {
    const formatter = new Intl.DateTimeFormat('zh-CN', {
        timeZone: timezone.value,
        month: 'numeric',
        day: 'numeric',
        weekday: 'short',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23',
    });
    const parts = Object.fromEntries(
        formatter.formatToParts(new Date()).map((part) => [part.type, part.value]),
    );

    currentTime.value = `${parts.month}月${parts.day}日 ${parts.weekday} ${parts.hour}:${parts.minute}:${parts.second}`;
};

watch(timezone, updateTime);

onMounted(() => {
    updateTime();
    timer = window.setInterval(updateTime, 1_000);
});

onBeforeUnmount(() => {
    if (timer !== undefined) window.clearInterval(timer);
});
</script>

<template>
    <time class="whitespace-nowrap text-sm font-semibold tabular-nums text-slate-800" :aria-label="`${timezone} 当前时间`">
        {{ currentTime }}
    </time>
</template>
