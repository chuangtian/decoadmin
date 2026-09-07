<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import EmptyState from '../../Components/Feedback/EmptyState.vue';
import Pagination from '../../Components/Navigation/Pagination.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useStoreDateTime } from '../../composables/useStoreDateTime';

interface ProductRow { id:number; title:string; handle:string; status:string; vendor:string|null; product_type:string|null; variants_count:number; variants_min_price:string|null; variants_max_price:string|null; synced_at:string|null }
interface Paginator<T> { data:T[]; links:Array<{url:string|null;label:string;active:boolean}> }
const props = defineProps<{ products:Paginator<ProductRow>; filters:{search?:string;status?:string} }>();
const form = useForm({ search: props.filters.search ?? '', status: props.filters.status ?? '' });
const submit = () => form.get('/products', { preserveState:true, replace:true });
const { formatDateTime: date } = useStoreDateTime();
const statusLabel = (value:string) => ({active:'销售中',draft:'草稿',archived:'已归档'}[value] ?? value);
const price = (value:string|null) => value === null ? '—' : Number(value).toFixed(2);
</script>
<template>
<Head title="商品管理" />
<AppLayout :breadcrumbs="[{label:'工作台',href:'/dashboard'},{label:'业务中心'},{label:'商品管理'}]">
  <div class="mx-auto max-w-7xl space-y-6">
    <header><p class="text-sm font-semibold text-emerald-700">Shopify 商品</p><h1 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">商品管理</h1><p class="mt-2 text-sm text-slate-500">查看当前店铺已同步的商品、变体和价格信息。</p></header>
    <form class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-[minmax(0,1fr)_180px_auto]" @submit.prevent="submit"><input v-model="form.search" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm outline-none focus:border-emerald-500 focus:bg-white" placeholder="搜索商品名称、Handle 或品牌" /><select v-model="form.status" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm"><option value="">全部状态</option><option value="active">销售中</option><option value="draft">草稿</option><option value="archived">已归档</option></select><button class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white">筛选</button></form>
    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><div v-if="products.data.length" class="overflow-x-auto"><table class="w-full min-w-[920px] text-left text-sm"><thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wider text-slate-500"><tr><th class="px-5 py-4">商品</th><th class="px-5 py-4">状态</th><th class="px-5 py-4">品牌 / 类型</th><th class="whitespace-nowrap px-5 py-4">变体</th><th class="px-5 py-4">价格</th><th class="px-5 py-4">最近同步</th></tr></thead><tbody class="divide-y divide-slate-100"><tr v-for="product in products.data" :key="product.id" class="hover:bg-slate-50"><td class="px-5 py-4"><Link :href="`/products/${product.id}`" class="font-semibold text-slate-950 hover:text-emerald-700">{{ product.title }}</Link><p class="mt-1 font-mono text-xs text-slate-400">{{ product.handle }}</p></td><td class="px-5 py-4"><span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">{{ statusLabel(product.status) }}</span></td><td class="px-5 py-4 text-slate-600">{{ product.vendor || '—' }}<p class="mt-1 text-xs text-slate-400">{{ product.product_type || '未分类' }}</p></td><td class="px-5 py-4 text-slate-700">{{ product.variants_count }}</td><td class="px-5 py-4 font-medium text-slate-800">{{ price(product.variants_min_price) }}<span v-if="product.variants_max_price && product.variants_max_price !== product.variants_min_price"> – {{ price(product.variants_max_price) }}</span></td><td class="px-5 py-4 text-xs text-slate-500">{{ date(product.synced_at) }}</td></tr></tbody></table></div><EmptyState v-else class="border-0 shadow-none" title="暂无商品数据" description="前往数据同步中心创建商品同步任务，完成后商品会显示在这里。" icon="products" /></section>
    <Pagination :links="products.links" />
  </div>
</AppLayout>
</template>
