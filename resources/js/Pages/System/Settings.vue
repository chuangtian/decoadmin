<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

interface GeneralSettings {
    platform_name: string;
    timezone: string;
    locale: string;
}

const props = defineProps<{ settings: GeneralSettings; canUpdate: boolean; timezones: string[] }>();
const form = useForm({ ...props.settings });
const save = () => form.put('/settings/general', { preserveScroll: true });
</script>

<template>
    <Head title="系统设置" />
    <AppLayout :breadcrumbs="[{ label: '系统管理' }, { label: '系统设置' }]">
        <div class="mx-auto max-w-5xl">
            <section class="mb-7 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold text-emerald-700">系统管理</p>
                    <h1 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">系统设置</h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">配置整个平台使用的系统名称、默认时区和界面语言。</p>
                </div>
                <span class="inline-flex self-start rounded-full px-3 py-1.5 text-xs font-semibold ring-1" :class="canUpdate ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-slate-200'">{{ canUpdate ? '可编辑' : '只读查看' }}</span>
            </section>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <header class="border-b border-slate-100 px-5 py-5 sm:px-7">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">平台基础信息</p>
                    <h2 class="mt-1 text-xl font-semibold text-slate-950">基础设置</h2>
                </header>

                <form class="p-5 sm:p-7" @submit.prevent="save">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <label class="sm:col-span-2">
                            <span class="text-sm font-semibold text-slate-800">系统名称</span>
                            <input v-model="form.platform_name" :disabled="!canUpdate" maxlength="80" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 disabled:bg-slate-50" />
                            <span v-if="form.errors.platform_name" class="mt-1 block text-xs text-rose-600">{{ form.errors.platform_name }}</span>
                        </label>
                        <label>
                            <span class="text-sm font-semibold text-slate-800">默认时区</span>
                            <select v-model="form.timezone" :disabled="!canUpdate" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 disabled:bg-slate-50">
                                <option v-for="timezone in timezones" :key="timezone" :value="timezone">{{ timezone }}</option>
                            </select>
                            <span v-if="form.errors.timezone" class="mt-1 block text-xs text-rose-600">{{ form.errors.timezone }}</span>
                        </label>
                        <label>
                            <span class="text-sm font-semibold text-slate-800">默认语言</span>
                            <select v-model="form.locale" :disabled="!canUpdate" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 disabled:bg-slate-50">
                                <option value="zh_CN">简体中文</option>
                                <option value="en">English</option>
                            </select>
                        </label>
                    </div>
                    <div v-if="canUpdate" class="mt-7 flex justify-end">
                        <button :disabled="form.processing" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-50">{{ form.processing ? '保存中…' : '保存系统设置' }}</button>
                    </div>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
