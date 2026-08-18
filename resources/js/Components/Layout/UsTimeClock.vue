<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

const currentTime = ref('');
let timer: number | undefined;

const formatter = new Intl.DateTimeFormat('zh-CN', {
    timeZone: 'America/New_York',
    month: 'numeric',
    day: 'numeric',
    weekday: 'short',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
});

const updateTime = () => {
    const parts = Object.fromEntries(
        formatter.formatToParts(new Date()).map((part) => [part.type, part.value]),
    );

    currentTime.value = `${parts.month}月${parts.day}日 ${parts.weekday} ${parts.hour}:${parts.minute}:${parts.second}`;
};

onMounted(() => {
    updateTime();
    timer = window.setInterval(updateTime, 1_000);
});

onBeforeUnmount(() => {
    if (timer !== undefined) window.clearInterval(timer);
});
</script>

<template>
    <time class="whitespace-nowrap text-sm font-semibold tabular-nums text-slate-800" aria-label="美国纽约当前时间">
        {{ currentTime }}
    </time>
</template>
