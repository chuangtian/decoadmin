<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import SecretSettingInput from '../../Components/Settings/SecretSettingInput.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

interface GeneralSettings {
    platform_name: string;
    timezone: string;
    locale: string;
}

interface StudentAiSettings {
    gemini_api_key: string;
    gemini_api_key_configured: boolean;
    gemini_model: string;
    auto_approval_threshold: number;
}

const props = defineProps<{ settings: GeneralSettings; canUpdate: boolean; timezones: string[]; aiSettings: StudentAiSettings | null; canUpdateAi: boolean }>();
const form = useForm({ ...props.settings });
const save = () => form.put('/settings/general', { preserveScroll: true });
const aiForm = useForm({
    gemini_api_key: '',
    gemini_model: props.aiSettings?.gemini_model ?? 'gemini-2.5-pro',
    auto_approval_threshold: props.aiSettings?.auto_approval_threshold ?? 80,
});
const saveAi = () => aiForm.put('/settings/student-ai', {
    preserveScroll: true,
    onSuccess: () => aiForm.gemini_api_key = '',
});
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

            <section v-if="aiSettings && canUpdateAi" class="mt-7 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <header class="border-b border-slate-100 px-5 py-5 sm:px-7">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-violet-700">AI 学生证识别</p>
                    <h2 class="mt-1 text-xl font-semibold text-slate-950">Gemini 审核设置</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-500">仅服务端使用。密钥不会回显；留空保存会保留已配置的 Key。</p>
                </header>
                <form class="p-5 sm:p-7" @submit.prevent="saveAi">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <SecretSettingInput
                            v-model="aiForm.gemini_api_key"
                            class="sm:col-span-2"
                            label="Gemini API Key"
                            :configured="aiSettings.gemini_api_key_configured"
                            :disabled="!canUpdateAi"
                            :error="aiForm.errors.gemini_api_key"
                            placeholder="留空则保留原 Key"
                            help="页面只显示配置状态，完整明文永不从服务端返回。"
                        />
                        <label>
                            <span class="text-sm font-semibold text-slate-800">Gemini 模型</span>
                            <input v-model="aiForm.gemini_model" :disabled="!canUpdateAi" maxlength="120" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 font-mono text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 disabled:bg-slate-50" />
                            <span v-if="aiForm.errors.gemini_model" class="mt-1 block text-xs text-rose-600">{{ aiForm.errors.gemini_model }}</span>
                        </label>
                        <label>
                            <span class="text-sm font-semibold text-slate-800">自动通过置信度阈值</span>
                            <div class="mt-2 flex items-center gap-2">
                                <input v-model="aiForm.auto_approval_threshold" :disabled="!canUpdateAi" type="number" min="0" max="100" step="0.01" class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 disabled:bg-slate-50" />
                                <span class="text-sm text-slate-500">/ 100</span>
                            </div>
                            <span v-if="aiForm.errors.auto_approval_threshold" class="mt-1 block text-xs text-rose-600">{{ aiForm.errors.auto_approval_threshold }}</span>
                        </label>
                    </div>
                    <div class="mt-7 flex justify-end">
                        <button :disabled="aiForm.processing" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-50">{{ aiForm.processing ? '保存中…' : '保存 AI 识别设置' }}</button>
                    </div>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
