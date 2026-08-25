<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

interface FieldOption { value: string; label: string }
interface ModuleField { key: string; label: string; type: 'text' | 'email' | 'time' | 'number' | 'select' | 'toggle'; value: unknown; placeholder?: string; min?: number; max?: number; options?: FieldOption[] }
interface MarketingModule { handle: string; name: string; product_name: string; description: string; capabilities: string[]; enabled: boolean; configured: boolean; credential_provider: string | null; fields: ModuleField[] }
interface CredentialProvider { key: string; title: string; configured: boolean; description: string }
const props = defineProps<{ store: { id: number; name: string; shopify_domain: string }; module: MarketingModule; credential: CredentialProvider | null }>();
const initial = Object.fromEntries(props.module.fields.map((field) => [field.key, field.value])) as Record<string, any>;
const form = useForm<Record<string, any>>(initial);
const save = () => form.put(`/stores/${props.store.id}/marketing/${props.module.handle}`, { preserveScroll: true });
</script>

<template>
    <Head :title="`${module.name} · ${store.name}`" />
    <AppLayout :breadcrumbs="[{ label: '应用中心', href: '/app-center' }, { label: '应用配置', href: '/app-configurations' }, { label: module.name }]">
        <div class="mx-auto max-w-6xl">
            <Link :href="`/stores/${store.id}/marketing`" class="text-sm font-semibold text-slate-500 hover:text-slate-900">← 返回营销中心</Link>
            <header class="mt-5 rounded-3xl bg-slate-950 px-6 py-7 text-white sm:px-8"><div class="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between"><div><p class="text-xs font-semibold uppercase tracking-[.18em] text-emerald-300">{{ module.product_name }}</p><h1 class="mt-2 text-3xl font-semibold">{{ module.name }}</h1><p class="mt-3 max-w-2xl text-sm leading-6 text-slate-300">{{ module.description }}</p></div><span class="w-fit rounded-full bg-emerald-400/15 px-3 py-1.5 text-xs font-semibold text-emerald-300 ring-1 ring-emerald-400/30">默认启用</span></div></header>

            <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
                <form class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" @submit.prevent="save">
                    <div><h2 class="text-lg font-semibold text-slate-950">店铺设置</h2><p class="mt-1 text-sm text-slate-500">设置仅作用于 {{ store.name }}，敏感 API 凭证单独加密保存。</p></div>
                    <div class="mt-6 grid gap-5 sm:grid-cols-2">
                        <label v-for="field in module.fields" :key="field.key" class="block" :class="field.type === 'toggle' ? 'sm:col-span-2' : ''">
                            <template v-if="field.type === 'toggle'"><span class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3"><span class="text-sm font-semibold text-slate-700">{{ field.label }}</span><input v-model="form[field.key]" type="checkbox" class="h-5 w-5 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" /></span></template>
                            <template v-else><span class="mb-2 block text-sm font-semibold text-slate-700">{{ field.label }}</span><select v-if="field.type === 'select'" v-model="form[field.key]" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm outline-none focus:border-emerald-500 focus:bg-white focus:ring-2 focus:ring-emerald-100"><option v-for="option in field.options" :key="option.value" :value="option.value">{{ option.label }}</option></select><input v-else-if="field.type === 'number'" v-model.number="form[field.key]" type="number" :min="field.min" :max="field.max" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm outline-none focus:border-emerald-500 focus:bg-white focus:ring-2 focus:ring-emerald-100" /><input v-else v-model="form[field.key]" :type="field.type" :placeholder="field.placeholder" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-sm outline-none focus:border-emerald-500 focus:bg-white focus:ring-2 focus:ring-emerald-100" /><p v-if="form.errors[field.key]" class="mt-1.5 text-xs text-rose-600">{{ form.errors[field.key] }}</p></template>
                        </label>
                    </div>
                    <div v-if="module.handle === 'sms'" class="mt-5 rounded-xl bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-800">短信营销始终要求明确授权；未订阅、已退订或缺少有效授权记录的客户不可发送。</div>
                    <div class="mt-6 flex justify-end"><button type="submit" :disabled="form.processing" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{{ form.processing ? '保存中…' : '保存设置' }}</button></div>
                </form>

                <aside class="space-y-5"><section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="font-semibold text-slate-950">模块能力</h2><ul class="mt-4 space-y-3"><li v-for="capability in module.capabilities" :key="capability" class="flex items-center gap-3 text-sm text-slate-600"><span class="grid h-6 w-6 place-items-center rounded-full bg-emerald-50 text-xs font-bold text-emerald-700">✓</span>{{ capability }}</li></ul></section><section v-if="credential" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between gap-3"><h2 class="font-semibold text-slate-950">{{ credential.title }}</h2><span class="text-xs font-semibold" :class="credential.configured ? 'text-emerald-700' : 'text-amber-700'">{{ credential.configured ? '已配置' : '未配置' }}</span></div><p class="mt-2 text-sm leading-6 text-slate-500">{{ credential.description }}</p><Link :href="`/store-settings/credentials?provider=${credential.key}`" class="mt-4 inline-flex text-sm font-semibold text-emerald-700">管理加密凭证 →</Link></section></aside>
            </div>
        </div>
    </AppLayout>
</template>
