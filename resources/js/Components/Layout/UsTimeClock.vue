<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useStoreDateTime } from '../../composables/useStoreDateTime';
import { formatStoreClock } from '../../utils/storeDateTime';

const currentTime = ref('');
const { timezone, now } = useStoreDateTime();
let timer: number | undefined;

const updateTime = () => {
    currentTime.value = formatStoreClock(now(), timezone.value);
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
    <div class="text-right" :title="`与当前 Shopify 店铺时区一致：${timezone}`">
        <time class="block whitespace-nowrap text-sm font-semibold tabular-nums text-slate-800" :aria-label="`${timezone} 当前时间`">{{ currentTime }}</time>
        <span class="mt-0.5 block whitespace-nowrap text-xs text-slate-500">店铺时间 · {{ timezone }}</span>
    </div>
</template>
