<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import ProductMonitorControl, { type ProductMonitor } from "../../Components/Shopify/ProductMonitorControl.vue";
import EmptyState from '../../Components/Feedback/EmptyState.vue';
import Pagination from '../../Components/Navigation/Pagination.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useStoreDateTime } from '../../composables/useStoreDateTime';

interface ProductRow { monitor?:ProductMonitor|null; id:number; title:string; handle:string; status:string; vendor:string|null; product_type:string|null; variants_count:number; variants_min_price:string|null; variants_max_price:string|null; synced_at:string|null }
interface Paginator<T> { data:T[]; links:Array<{url:string|null;label:string;active:boolean}> }
const props = defineProps<{ products:Paginator<ProductRow>; canManageMonitor:boolean; filters:{search?:string;status?:string} }>();
const form = useForm({ search: props.filters.search ?? '', status: props.filters.status ?? '' });
const submit = () => form.get('/products', { preserveState:true, replace:true });
const { formatDateTime: date } = useStoreDateTime();
const statusLabel = (value:string) => ({active:'销售中',draft:'草稿',archived:'已归档'}[value] ?? value);
const price = (value:string|null) => value === null ? '—' : Number(value).toFixed(2);
</script>
<template>
<Head title="商品管理" />
<AppLayout :breadcrumbs="[{label:'工作台',href:'/dashboard'},{label:'业务中心'},{label:'商品管理'}]">
  <div class="mx-auto max-w-[1440px] space-y-5">
    <header><p class="text-sm font-semibold text-emerald-700">Shopify 商品</p><h1 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">商品管理</h1><p class="mt-2 text-sm text-slate-500">只有设为重点的产品才监控；每 5 分钟只读检查状态、在线商店发布情况及各变体/地点库存。首次建立基线不提醒；低库存阈值默认 10，可修改（0 表示仅缺货）。</p></header>
    <form class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-[minmax(0,1fr)_180px_auto]" @submit.prevent="submit"><input v-model="form.search" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm outline-none focus:border-emerald-500 focus:bg-white" placeholder="搜索商品名称、Handle 或品牌" /><select v-model="form.status" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm"><option value="">全部状态</option><option value="active">销售中</option><option value="draft">草稿</option><option value="archived">已归档</option></select><button class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white">筛选</button></form>
    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><div v-if="products.data.length" class="overflow-x-auto"><table class="product-table w-full min-w-[1240px] table-fixed text-left text-sm"><colgroup><col style="width:24%"/><col style="width:8%"/><col style="width:13%"/><col style="width:5%"/><col style="width:12%"/><col style="width:14%"/><col style="width:24%"/></colgroup><thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wider text-slate-500"><tr><th class="px-4 py-3">商品</th><th class="px-4 py-3">状态</th><th class="px-4 py-3">品牌 / 类型</th><th class="whitespace-nowrap px-4 py-3">变体</th><th class="px-4 py-3">价格</th><th class="px-4 py-3">最近同步</th><th class="px-4 py-3">重点监控</th></tr></thead><tbody class="divide-y divide-slate-100"><tr v-for="product in products.data" :key="product.id" class="hover:bg-slate-50"><td class="px-4 py-3"><Link :href="`/products/${product.id}`"  :title="product.title" class="line-clamp-2 font-medium leading-5 text-slate-950 hover:text-emerald-700">{{ product.title }}</Link><p :title="product.handle" class="mt-1 truncate text-xs text-slate-400">{{ product.handle }}</p></td><td class="px-4 py-3"><span class="inline-flex whitespace-nowrap rounded-full px-2 py-1 text-xs font-medium" :class="product.status === 'active' ? 'bg-emerald-50 text-emerald-700' : product.status === 'draft' ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-500'">{{ statusLabel(product.status) }}</span></td><td class="px-4 py-3 text-slate-600"><p class="truncate" :title="product.vendor || undefined">{{ product.vendor || '—' }}</p><p class="mt-1 truncate text-xs text-slate-400">{{ product.product_type || '未分类' }}</p></td><td class="px-4 py-3 text-slate-700">{{ product.variants_count }}</td><td class="whitespace-nowrap px-4 py-3 font-medium tabular-nums text-slate-800">{{ price(product.variants_min_price) }}<span v-if="product.variants_max_price && product.variants_max_price !== product.variants_min_price"> – {{ price(product.variants_max_price) }}</span></td><td class="whitespace-nowrap px-4 py-3 text-xs tabular-nums text-slate-500">{{ date(product.synced_at) }}</td><td class="px-4 py-3"><ProductMonitorControl :product-id="product.id" :monitor="product.monitor" :can-manage="canManageMonitor" /></td></tr></tbody></table></div><EmptyState v-else class="border-0 shadow-none" title="暂无商品数据" description="前往数据同步中心创建商品同步任务，完成后商品会显示在这里。" icon="products" /></section>
    <Pagination :links="products.links" />
  </div>
</AppLayout>
</template>

<style scoped>
.product-table th { white-space: nowrap; font-weight: 600; }
.product-table td { vertical-align: middle; }
.product-table tbody tr { height: 82px; }
</style>
