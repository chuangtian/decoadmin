<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import EmptyState from '../../Components/Feedback/EmptyState.vue';
import Pagination from '../../Components/Navigation/Pagination.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

interface LocationRow { id:number; name:string; shopify_location_id:string; address:Record<string,string|null>|null; active:boolean; inventory_levels_count:number; available_total:number|null; synced_at:string|null }
interface Page<T> { data:T[]; links:Array<{url:string|null;label:string;active:boolean}> }
const props = defineProps<{ locations:Page<LocationRow>; filters:{search?:string;active?:string} }>();
const form = useForm({ search:props.filters.search ?? '', active:props.filters.active ?? '' });
const submit = () => form.get('/locations', { preserveState:true, replace:true });
const address = (value:Record<string,string|null>|null) => value ? [value.address1, value.city, value.province, value.country].filter(Boolean).join(' · ') : '未提供地址';
const date = (value:string|null) => value ? new Intl.DateTimeFormat('zh-CN', { dateStyle:'medium', timeStyle:'short' }).format(new Date(value)) : '—';
</script>

<template>
    <Head title="地点管理" />
    <AppLayout :breadcrumbs="[{label:'工作台',href:'/dashboard'},{label:'业务中心'},{label:'地点管理'}]">
        <div class="mx-auto max-w-7xl space-y-6">
            <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div><p class="text-sm font-semibold text-emerald-700">Shopify Location</p><h1 class="mt-1 text-3xl font-semibold text-slate-950">地点管理</h1><p class="mt-2 text-sm text-slate-500">查看当前店铺的仓库、门店及各地点库存汇总。</p></div><Link href="/inventory" class="text-sm font-semibold text-emerald-700">查看库存项目 →</Link></header>
            <form class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-[minmax(0,1fr)_180px_auto]" @submit.prevent="submit"><input v-model="form.search" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm" placeholder="搜索地点名称" /><select v-model="form.active" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm"><option value="">全部状态</option><option value="1">启用</option><option value="0">停用</option></select><button class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white">筛选</button></form>
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><div v-if="locations.data.length" class="overflow-x-auto"><table class="w-full min-w-[880px] text-left text-sm"><thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wider text-slate-500"><tr><th class="px-5 py-4">地点</th><th class="px-5 py-4">地址</th><th class="px-5 py-4">状态</th><th class="px-5 py-4">库存项目</th><th class="px-5 py-4">可用库存</th><th class="px-5 py-4">最近同步</th></tr></thead><tbody class="divide-y divide-slate-100"><tr v-for="location in locations.data" :key="location.id" class="hover:bg-slate-50"><td class="px-5 py-4"><Link :href="`/locations/${location.id}`" class="font-semibold text-slate-950 hover:text-emerald-700">{{location.name}}</Link><p class="mt-1 font-mono text-xs text-slate-400">{{location.shopify_location_id}}</p></td><td class="px-5 py-4 text-slate-600">{{address(location.address)}}</td><td class="px-5 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="location.active?'bg-emerald-50 text-emerald-700':'bg-slate-100 text-slate-500'">{{location.active?'启用':'停用'}}</span></td><td class="px-5 py-4">{{location.inventory_levels_count}}</td><td class="px-5 py-4 text-lg font-semibold text-slate-950">{{location.available_total ?? 0}}</td><td class="px-5 py-4 text-xs text-slate-500">{{date(location.synced_at)}}</td></tr></tbody></table></div><EmptyState v-else class="border-0 shadow-none" title="暂无地点数据" description="完成库存同步后，Shopify 地点会显示在这里。" icon="stores" /></section>
            <Pagination :links="locations.links" />
        </div>
    </AppLayout>
</template>
