<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import EmptyState from '../../Components/Feedback/EmptyState.vue';
import Pagination from '../../Components/Navigation/Pagination.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

interface Category { id: number; name: string; type: 'income' | 'expense'; color: string; is_active: boolean }
interface Store { id: number; name: string }
interface Entry {
    id: number; uuid: string; type: 'income' | 'expense'; amount: string; currency: string;
    occurred_on: string; description: string; reference: string | null; category: { id: number; name: string } | null;
    store: Store | null; creator: { id: number; name: string } | null; created_at: string | null;
}
interface Page<T> { data: T[]; links: Array<{ url: string | null; label: string; active: boolean }> }

const props = defineProps<{
    organization: { id: number; name: string };
    canManage: boolean;
    finance: {
        filters: { month: string; store_id: number | null };
        currency: string;
        summary: { income: number; expense: number; profit: number };
        monthly: Array<{ month: string; label: string; income: number; expense: number; profit: number }>;
        store_profit: Array<{ id: number; name: string; income: number; expense: number; profit: number }>;
        categories: Category[];
        stores: Store[];
        entries: Page<Entry>;
    };
}>();

const filter = useForm({ month: props.finance.filters.month, store_id: props.finance.filters.store_id?.toString() ?? '' });
const category = useForm({ name: '', type: 'expense' as 'income' | 'expense', color: 'slate', is_active: true });
const entry = useForm({
    type: 'expense' as 'income' | 'expense', category_id: '', store_id: '', amount: '',
    occurred_on: new Date().toISOString().slice(0, 10), description: '', reference: '',
});
const matchingCategories = computed(() => props.finance.categories.filter((item) => item.type === entry.type && item.is_active));
const maxMonthly = computed(() => Math.max(...props.finance.monthly.flatMap((item) => [item.income, item.expense]), 1));
const money = (value: number | string) => new Intl.NumberFormat('zh-CN', { style: 'currency', currency: props.finance.currency }).format(Number(value || 0));
const applyFilters = () => router.get('/finance', { month: filter.month, store_id: filter.store_id || undefined }, { preserveState: true });
const saveCategory = () => category.post('/finance/categories', { preserveScroll: true, onSuccess: () => category.reset('name') });
const saveEntry = () => entry.post('/finance/entries', { preserveScroll: true, onSuccess: () => entry.reset('amount', 'description', 'reference') });
const remove = (id: number) => {
    if (window.confirm('确定删除这条收支记录吗？')) router.delete(`/finance/entries/${id}`, { preserveScroll: true });
};
const date = (value: string) => new Intl.DateTimeFormat('zh-CN', { dateStyle: 'medium' }).format(new Date(`${value}T00:00:00`));
</script>

<template>
    <Head title="公司财务" />
    <AppLayout :breadcrumbs="[{ label: '公司财务' }]">
        <div class="mx-auto max-w-7xl space-y-6">
            <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold text-emerald-700">公司范围</p>
                    <h1 class="mt-1 text-3xl font-semibold text-slate-950">公司财务</h1>
                    <p class="mt-2 text-sm text-slate-500">记录简单收支、费用分类、店铺利润和月度汇总。</p>
                </div>
                <form class="grid gap-3 sm:grid-cols-[160px_minmax(190px,1fr)_auto]" @submit.prevent="applyFilters">
                    <input v-model="filter.month" type="month" class="min-w-0 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold shadow-sm" />
                    <select v-model="filter.store_id" class="min-w-0 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold shadow-sm">
                        <option value="">全部公司与店铺</option>
                        <option v-for="store in finance.stores" :key="store.id" :value="store.id">{{ store.name }}</option>
                    </select>
                    <button class="rounded-xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white">查看</button>
                </form>
            </header>

            <section class="grid gap-4 md:grid-cols-3">
                <article v-for="item in [
                    { label: '本月收入', value: finance.summary.income, color: 'text-emerald-700' },
                    { label: '本月支出', value: finance.summary.expense, color: 'text-rose-700' },
                    { label: '本月利润', value: finance.summary.profit, color: finance.summary.profit >= 0 ? 'text-blue-700' : 'text-rose-700' },
                ]" :key="item.label" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <p class="text-sm font-semibold text-slate-500">{{ item.label }}</p>
                    <p class="mt-4 text-3xl font-semibold tracking-tight" :class="item.color">{{ money(item.value) }}</p>
                </article>
            </section>

            <section class="grid gap-5 xl:grid-cols-[1.5fr_1fr]">
                <article class="overflow-x-auto rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="font-semibold text-slate-900">最近 6 个月</h2>
                    <div class="mt-8 flex h-56 min-w-[520px] items-end gap-5">
                        <div v-for="month in finance.monthly" :key="month.month" class="flex flex-1 flex-col items-center gap-2">
                            <div class="flex h-44 w-full items-end justify-center gap-1.5">
                                <div class="w-1/3 rounded-t-lg bg-emerald-500" :style="{ height: `${Math.max((month.income / maxMonthly) * 170, month.income ? 4 : 2)}px` }" />
                                <div class="w-1/3 rounded-t-lg bg-rose-400" :style="{ height: `${Math.max((month.expense / maxMonthly) * 170, month.expense ? 4 : 2)}px` }" />
                            </div>
                            <span class="text-xs text-slate-500">{{ month.label }}</span>
                        </div>
                    </div>
                    <div class="mt-3 flex gap-5 text-xs font-semibold text-slate-500">
                        <span><i class="mr-2 inline-block h-2.5 w-2.5 rounded bg-emerald-500" />收入</span>
                        <span><i class="mr-2 inline-block h-2.5 w-2.5 rounded bg-rose-400" />支出</span>
                    </div>
                </article>
                <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-6 py-5"><h2 class="font-semibold text-slate-900">店铺利润</h2><p class="mt-1 text-sm text-slate-500">当前所选月份</p></div>
                    <div v-if="finance.store_profit.length" class="divide-y divide-slate-100">
                        <div v-for="store in finance.store_profit" :key="store.id" class="px-6 py-4">
                            <div class="flex items-center justify-between gap-4"><strong class="text-sm text-slate-900">{{ store.name }}</strong><span class="text-sm font-semibold" :class="store.profit >= 0 ? 'text-emerald-700' : 'text-rose-700'">{{ money(store.profit) }}</span></div>
                            <p class="mt-1 text-xs text-slate-400">收入 {{ money(store.income) }} · 支出 {{ money(store.expense) }}</p>
                        </div>
                    </div>
                    <EmptyState v-else title="暂无店铺账目" description="创建关联店铺的收支记录后显示利润。" icon="analytics" />
                </article>
            </section>

            <section v-if="canManage" class="grid gap-5 xl:grid-cols-[1fr_2fr]">
                <form class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" @submit.prevent="saveCategory">
                    <h2 class="font-semibold text-slate-900">新增分类</h2>
                    <div class="mt-5 space-y-4">
                        <input v-model="category.name" required class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm" placeholder="例如：广告费用" />
                        <select v-model="category.type" class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm"><option value="income">收入分类</option><option value="expense">支出分类</option></select>
                        <button :disabled="category.processing" class="w-full rounded-xl bg-slate-950 px-4 py-3 text-sm font-semibold text-white">创建分类</button>
                    </div>
                </form>
                <form class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" @submit.prevent="saveEntry">
                    <h2 class="font-semibold text-slate-900">新增收支记录</h2>
                    <div class="mt-5 grid gap-4 md:grid-cols-2">
                        <select v-model="entry.type" class="rounded-xl border border-slate-200 px-4 py-3 text-sm" @change="entry.category_id = ''"><option value="income">收入</option><option value="expense">支出</option></select>
                        <select v-model="entry.category_id" required class="rounded-xl border border-slate-200 px-4 py-3 text-sm"><option value="">选择分类</option><option v-for="item in matchingCategories" :key="item.id" :value="item.id">{{ item.name }}</option></select>
                        <select v-model="entry.store_id" class="rounded-xl border border-slate-200 px-4 py-3 text-sm"><option value="">公司公共账目</option><option v-for="store in finance.stores" :key="store.id" :value="store.id">{{ store.name }}</option></select>
                        <input v-model="entry.amount" required type="number" min="0.01" step="0.01" class="rounded-xl border border-slate-200 px-4 py-3 text-sm" placeholder="金额" />
                        <input v-model="entry.occurred_on" required type="date" class="rounded-xl border border-slate-200 px-4 py-3 text-sm" />
                        <input v-model="entry.reference" class="rounded-xl border border-slate-200 px-4 py-3 text-sm" placeholder="参考编号（可选）" />
                        <textarea v-model="entry.description" required class="rounded-xl border border-slate-200 px-4 py-3 text-sm md:col-span-2" rows="3" placeholder="收支说明" />
                        <button :disabled="entry.processing" class="rounded-xl bg-slate-950 px-4 py-3 text-sm font-semibold text-white md:col-span-2">保存记录</button>
                    </div>
                </form>
            </section>

            <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-6 py-5"><h2 class="font-semibold text-slate-900">收支记录</h2></div>
                <div v-if="finance.entries.data.length" class="overflow-x-auto">
                    <table class="w-full min-w-[920px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs font-semibold text-slate-500"><tr><th class="px-6 py-4">日期</th><th class="px-6 py-4">说明</th><th class="px-6 py-4">分类</th><th class="px-6 py-4">店铺</th><th class="px-6 py-4">金额</th><th class="px-6 py-4">记录人</th><th v-if="canManage" class="px-6 py-4">操作</th></tr></thead>
                        <tbody class="divide-y divide-slate-100"><tr v-for="item in finance.entries.data" :key="item.id"><td class="px-6 py-4 text-slate-500">{{ date(item.occurred_on) }}</td><td class="px-6 py-4"><p class="font-semibold text-slate-900">{{ item.description }}</p><p v-if="item.reference" class="mt-1 text-xs text-slate-400">{{ item.reference }}</p></td><td class="px-6 py-4">{{ item.category?.name ?? '—' }}</td><td class="px-6 py-4">{{ item.store?.name ?? '公司公共' }}</td><td class="px-6 py-4 font-semibold" :class="item.type === 'income' ? 'text-emerald-700' : 'text-rose-700'">{{ item.type === 'income' ? '+' : '-' }} {{ money(item.amount) }}</td><td class="px-6 py-4 text-slate-500">{{ item.creator?.name ?? '系统' }}</td><td v-if="canManage" class="px-6 py-4"><button class="text-sm font-semibold text-rose-600" @click="remove(item.id)">删除</button></td></tr></tbody>
                    </table>
                </div>
                <EmptyState v-else title="暂无收支记录" description="选择月份内还没有记录公司收支。" icon="analytics" />
            </section>
            <Pagination :links="finance.entries.links" />
        </div>
    </AppLayout>
</template>
