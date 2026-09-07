<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { useStoreDateTime } from '../../composables/useStoreDateTime';
export interface ProductMonitor { is_enabled:boolean; low_stock_threshold:number; last_checked_at:string|null; last_success_at:string|null; last_error:string|null }
const props = defineProps<{ productId:number; monitor?:ProductMonitor|null; canManage:boolean }>();
const form = useForm({ is_enabled:props.monitor?.is_enabled ?? false, low_stock_threshold:props.monitor?.low_stock_threshold ?? 10 });
watch(() => props.monitor, (value) => { form.is_enabled = value?.is_enabled ?? false; form.low_stock_threshold = value?.low_stock_threshold ?? 10; });
const { formatDateTime } = useStoreDateTime();
const save = (enabled:boolean) => {
    form.is_enabled = enabled;
    form.patch(`/products/${props.productId}/monitor`, { preserveScroll:true, onError:() => { form.is_enabled = props.monitor?.is_enabled ?? false; } });
};
</script>
<template>
  <div class="product-monitor space-y-1.5">
    <div class="flex items-center gap-2">
    <button type="button" :disabled="!canManage || form.processing" @click="save(!monitor?.is_enabled)"
      :aria-pressed="!!monitor?.is_enabled" :title="monitor?.is_enabled ? '点击取消重点监控' : '仅监控此产品，不修改 Shopify 数据'" class="monitor-toggle shrink-0 whitespace-nowrap rounded-lg border text-xs font-semibold transition-colors disabled:opacity-60 focus-visible:outline-2 focus-visible:outline-emerald-600"
      :class="monitor?.is_enabled ? 'border-amber-300 bg-amber-50 text-amber-800' : 'border-slate-200 text-slate-600'">
      {{ form.processing ? '保存中…' : monitor?.is_enabled ? '★ 监控中' : '☆ 设为重点' }}
    </button>
    <div v-if="canManage || monitor?.is_enabled" class="flex items-center gap-1.5 whitespace-nowrap text-xs text-slate-500">
      <label :for="`stock-threshold-${productId}`">低库存 ≤</label>
      <input :id="`stock-threshold-${productId}`" v-model.number="form.low_stock_threshold" type="number" min="0" max="1000000" :disabled="!canManage || form.processing" class="threshold-input rounded-md border border-slate-200 bg-white text-center tabular-nums focus:border-emerald-500 focus:outline-none" />
      <button v-if="canManage && monitor?.is_enabled && form.low_stock_threshold !== monitor.low_stock_threshold" :disabled="form.processing" type="button" @click="save(true)" class="text-emerald-700">保存</button>
    </div>
    </div>
    <p v-for="error in form.errors" :key="error" class="text-xs text-red-600" role="alert">{{ error }}</p>
    <p v-if="monitor?.is_enabled" class="max-w-72 truncate text-[11px]" :title="monitor.last_error || (monitor.last_success_at ? formatDateTime(monitor.last_success_at) : '等待首次检查建立基线')" :class="monitor.last_error ? 'text-red-600' : 'text-slate-400'">
      {{ monitor.last_error || (monitor.last_success_at ? `最近成功检查：${formatDateTime(monitor.last_success_at)}` : '等待首次检查建立基线') }}
    </p>
  </div>
</template>
<style scoped>
.monitor-toggle { height: 30px; min-height: 30px; padding: 0 10px; }
.threshold-input { width: 64px; height: 28px; min-height: 28px; padding: 2px 4px; font-size: 12px; }
</style>
