<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import SecretSettingInput from '../../Components/Settings/SecretSettingInput.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

interface FeishuSettings {
    enabled: boolean;
    app_id: string;
    app_secret: string;
    verification_token: string;
    encrypt_key: string;
    bot_webhook_url: string;
    app_secret_configured: boolean;
    verification_token_configured: boolean;
    encrypt_key_configured: boolean;
    bot_webhook_configured: boolean;
}

const props = defineProps<{ settings: FeishuSettings; canUpdate: boolean }>();
const form = useForm({
    enabled: props.settings.enabled,
    app_id: props.settings.app_id,
    app_secret: props.settings.app_secret,
    verification_token: props.settings.verification_token,
    encrypt_key: props.settings.encrypt_key,
    bot_webhook_url: props.settings.bot_webhook_url,
});
const save = () => form.put('/settings/feishu', { preserveScroll: true });
</script>

<template>
    <Head title="飞书设置" />
    <AppLayout :breadcrumbs="[{ label: '系统管理' }, { label: '飞书设置' }]">
        <div class="mx-auto max-w-5xl">
            <section class="mb-7 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold text-emerald-700">系统管理</p>
                    <h1 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">飞书设置</h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">配置飞书开放平台应用身份、事件验证和机器人 Webhook。</p>
                </div>
                <span class="inline-flex self-start rounded-full px-3 py-1.5 text-xs font-semibold ring-1" :class="canUpdate ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-slate-200'">{{ canUpdate ? '可编辑' : '只读查看' }}</span>
            </section>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <header class="border-b border-slate-100 px-5 py-5 sm:px-7">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">开放平台与机器人基础</p>
                    <h2 class="mt-1 text-xl font-semibold text-slate-950">飞书接入</h2>
                </header>

                <form class="p-5 sm:p-7" @submit.prevent="save">
                    <div class="mb-6 flex items-start justify-between gap-4 rounded-2xl bg-violet-50 p-4 ring-1 ring-violet-100">
                        <div>
                            <h3 class="text-sm font-semibold text-violet-950">飞书开放平台接入基础</h3>
                            <p class="mt-1 text-xs leading-5 text-violet-700">当前只保存配置，不会主动发送消息或订阅业务事件。后续飞书功能将复用这里的系统级接入参数。</p>
                        </div>
                        <label class="relative inline-flex shrink-0 cursor-pointer items-center">
                            <input v-model="form.enabled" :disabled="!canUpdate" type="checkbox" class="peer sr-only" />
                            <span class="h-6 w-11 rounded-full bg-slate-300 transition peer-checked:bg-violet-500 peer-disabled:opacity-50 after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:transition peer-checked:after:translate-x-5" />
                        </label>
                    </div>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <label class="sm:col-span-2"><span class="text-sm font-semibold text-slate-800">App ID</span><input v-model="form.app_id" :disabled="!canUpdate" autocomplete="off" placeholder="cli_xxxxxxxxxxxxxxxx" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 font-mono text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 disabled:bg-slate-50" /><span v-if="form.errors.app_id" class="mt-1 block text-xs text-rose-600">{{ form.errors.app_id }}</span></label>
                        <SecretSettingInput v-model="form.app_secret" label="App Secret" :disabled="!canUpdate" :configured="settings.app_secret_configured" :error="form.errors.app_secret" />
                        <SecretSettingInput v-model="form.verification_token" label="Verification Token" :disabled="!canUpdate" :configured="settings.verification_token_configured" :error="form.errors.verification_token" />
                        <SecretSettingInput v-model="form.encrypt_key" label="Encrypt Key" :disabled="!canUpdate" :configured="settings.encrypt_key_configured" :error="form.errors.encrypt_key" />
                        <SecretSettingInput v-model="form.bot_webhook_url" label="机器人 Webhook" :disabled="!canUpdate" :configured="settings.bot_webhook_configured" :error="form.errors.bot_webhook_url" placeholder="https://open.feishu.cn/open-apis/bot/v2/hook/..." />
                    </div>
                    <div v-if="canUpdate" class="mt-7 flex justify-end"><button :disabled="form.processing" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-50">{{ form.processing ? '保存中…' : '保存飞书设置' }}</button></div>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
