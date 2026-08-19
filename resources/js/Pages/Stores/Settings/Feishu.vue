<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import SecretSettingInput from '../../../Components/Settings/SecretSettingInput.vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

interface FeishuSettings {
    feishu_enabled: boolean;
    feishu_webhook_url: string;
    feishu_webhook_configured: boolean;
    feishu_secret: string;
    feishu_secret_configured: boolean;
}

interface FeishuTableSettings {
    feishu_table_app_id: string;
    feishu_table_app_secret: string;
    feishu_table_app_secret_configured: boolean;
    feishu_table_configured: boolean;
}

const props = defineProps<{
    store: { id: number; name: string; shopify_domain: string };
    settings: FeishuSettings;
    tableSettings: FeishuTableSettings;
    canUpdate: boolean;
}>();

const form = useForm({
    feishu_enabled: props.settings.feishu_enabled,
    feishu_webhook_url: props.settings.feishu_webhook_url,
    feishu_secret: props.settings.feishu_secret,
});
const toggleSaving = ref(false);
const save = () => form.put('/store-settings/feishu', { preserveScroll: true });
const toggleFeishu = (event: Event) => {
    const enabled = (event.target as HTMLInputElement).checked;
    form.feishu_enabled = enabled;
    toggleSaving.value = true;
    router.patch('/store-settings/feishu/enabled', { enabled }, {
        preserveScroll: true,
        onError: () => { form.feishu_enabled = !enabled; },
        onFinish: () => { toggleSaving.value = false; },
    });
};
</script>

<template>
    <Head :title="`${store.name} 飞书设置`" />
    <AppLayout :breadcrumbs="[{ label: '店铺设置' }, { label: '飞书设置' }]">
        <div class="mx-auto max-w-5xl space-y-6">
            <header>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">当前店铺 · {{ store.name }}</p>
                <h1 class="mt-2 text-2xl font-semibold text-slate-950">飞书设置</h1>
                <p class="mt-2 text-sm text-slate-500">机器人通知按店铺独立配置；飞书开放平台应用由所有店铺共享。</p>
            </header>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <header class="border-b border-slate-100 px-5 py-5 sm:px-7">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-violet-700">店铺独立消息通道</p>
                    <h2 class="mt-1 text-xl font-semibold text-slate-950">飞书机器人</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-500">将当前店铺的异常发送到指定飞书群，配置只作用于当前店铺。</p>
                </header>

                <form class="p-5 sm:p-7" @submit.prevent="save">
                    <div class="mb-7 flex items-start justify-between gap-4 rounded-2xl bg-violet-50 p-4 ring-1 ring-violet-100">
                        <div>
                            <h3 class="text-sm font-semibold text-violet-950">启用飞书机器人</h3>
                            <p class="mt-1 text-xs leading-5 text-violet-700">点击开关后立即保存，无需再提交整张表单。</p>
                        </div>
                        <label class="relative inline-flex shrink-0 cursor-pointer items-center">
                            <input v-model="form.feishu_enabled" :disabled="!canUpdate || toggleSaving" type="checkbox" class="peer sr-only" @change="toggleFeishu($event)" />
                            <span class="h-6 w-11 rounded-full bg-slate-300 transition peer-checked:bg-violet-500 peer-disabled:opacity-50 after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:transition peer-checked:after:translate-x-5" />
                        </label>
                    </div>

                    <div class="grid gap-6">
                        <SecretSettingInput v-model="form.feishu_webhook_url" label="Webhook 地址" :disabled="!canUpdate" :configured="settings.feishu_webhook_configured" :error="form.errors.feishu_webhook_url" placeholder="https://open.feishu.cn/open-apis/bot/v2/hook/..." help="留空会保留已保存的地址。" />
                        <SecretSettingInput v-model="form.feishu_secret" label="签名密钥" :disabled="!canUpdate" :configured="settings.feishu_secret_configured" :error="form.errors.feishu_secret" help="未启用签名校验时可以留空；留空会保留已保存的密钥。" />
                    </div>

                    <div v-if="canUpdate" class="mt-7 flex justify-end">
                        <button :disabled="form.processing" class="rounded-xl bg-slate-950 px-6 py-3 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-50">{{ form.processing ? '保存中…' : '保存机器人配置' }}</button>
                    </div>
                </form>
            </section>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <header class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 px-5 py-5 sm:px-7">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-700">共享开放平台应用</p>
                        <h2 class="mt-1 text-xl font-semibold text-slate-950">飞书表格凭证</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">用于后续读取飞书表格。由平台环境统一配置，所有店铺默认使用同一套凭证。</p>
                    </div>
                    <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="tableSettings.feishu_table_configured ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">
                        {{ tableSettings.feishu_table_configured ? '已配置' : '未配置' }}
                    </span>
                </header>

                <div class="grid gap-5 p-5 sm:p-7">
                    <label class="block">
                        <span class="text-sm font-semibold text-slate-800">App ID</span>
                        <input :value="tableSettings.feishu_table_app_id" disabled class="mt-2 w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 font-mono text-sm text-slate-700 outline-none" />
                    </label>
                    <SecretSettingInput :model-value="tableSettings.feishu_table_app_secret" label="App Secret" disabled :configured="tableSettings.feishu_table_app_secret_configured" help="此密钥来自当前运行环境，不会保存到店铺数据库，也不会提交到 Git。" />
                </div>
            </section>
        </div>
    </AppLayout>
</template>
