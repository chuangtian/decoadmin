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
  <div class="min-w-52 space-y-2">
    <button type="button" :disabled="!canManage || form.processing" @click="save(!monitor?.is_enabled)"
      :aria-pressed="!!monitor?.is_enabled" class="rounded-xl border px-3 py-2 text-xs font-semibold disabled:opacity-60"
      :class="monitor?.is_enabled ? 'border-amber-300 bg-amber-50 text-amber-800' : 'border-slate-200 text-slate-600'">
      {{ form.processing ? '保存中…' : monitor?.is_enabled ? '★ 重点监控中 · 取消' : '☆ 设为重点' }}
    </button>
    <div v-if="canManage || monitor?.is_enabled" class="flex items-center gap-1 text-xs text-slate-500">
      <label :for="`stock-threshold-${productId}`">低库存 ≤</label>
      <input :id="`stock-threshold-${productId}`" v-model.number="form.low_stock_threshold" type="number" min="0" max="1000000" :disabled="!canManage || form.processing" class="w-16 rounded border border-slate-200 px-1 py-1" />
      <button v-if="canManage && monitor?.is_enabled && form.low_stock_threshold !== monitor.low_stock_threshold" :disabled="form.processing" type="button" @click="save(true)" class="text-emerald-700">保存</button>
    </div>
    <p v-for="error in form.errors" :key="error" class="text-xs text-red-600" role="alert">{{ error }}</p>
    <p v-if="monitor?.is_enabled" class="max-w-64 text-xs" :class="monitor.last_error ? 'text-red-600' : 'text-slate-400'">
      {{ monitor.last_error || (monitor.last_success_at ? `最近成功检查：${formatDateTime(monitor.last_success_at)}` : '等待首次检查建立基线') }}
    </p>
  </div>
</template>
