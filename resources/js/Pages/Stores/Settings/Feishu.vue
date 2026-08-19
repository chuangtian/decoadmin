<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import SecretSettingInput from '../../../Components/Settings/SecretSettingInput.vue';
import StoreSettingsHeader from '../../../Components/Stores/StoreSettingsHeader.vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

interface FeishuSettings {
    feishu_enabled: boolean;
    feishu_webhook_url: string;
    feishu_webhook_configured: boolean;
    feishu_secret: string;
    feishu_secret_configured: boolean;
}

const props = defineProps<{
    store: { id: number; name: string; shopify_domain: string };
    settings: FeishuSettings;
    canUpdate: boolean;
}>();
const form = useForm({
    feishu_enabled: props.settings.feishu_enabled,
    feishu_webhook_url: props.settings.feishu_webhook_url,
    feishu_secret: props.settings.feishu_secret,
});
const save = () => form.put('/store-settings/feishu', { preserveScroll: true });
</script>

<template>
    <Head :title="`${store.name} 飞书设置`" />
    <AppLayout :breadcrumbs="[{ label: '店铺设置' }, { label: '飞书设置' }]">
        <div class="mx-auto max-w-5xl">
            <StoreSettingsHeader :store="store" active="feishu" :can-update="canUpdate" />

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <header class="border-b border-slate-100 px-5 py-5 sm:px-7">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-violet-700">店铺独立消息通道</p>
                    <h2 class="mt-1 text-xl font-semibold text-slate-950">飞书机器人</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-500">将当前店铺的异常发送到指定飞书群。暂时只保留一套机器人配置。</p>
                </header>

                <form class="p-5 sm:p-7" @submit.prevent="save">
                    <div class="mb-7 flex items-start justify-between gap-4 rounded-2xl bg-violet-50 p-4 ring-1 ring-violet-100">
                        <div>
                            <h3 class="text-sm font-semibold text-violet-950">启用飞书机器人</h3>
                            <p class="mt-1 text-xs leading-5 text-violet-700">启用后，当前店铺的同步、Webhook 和连接异常可发送至该机器人。</p>
                        </div>
                        <label class="relative inline-flex shrink-0 cursor-pointer items-center">
                            <input v-model="form.feishu_enabled" :disabled="!canUpdate" type="checkbox" class="peer sr-only" />
                            <span class="h-6 w-11 rounded-full bg-slate-300 transition peer-checked:bg-violet-500 peer-disabled:opacity-50 after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:transition peer-checked:after:translate-x-5" />
                        </label>
                    </div>

                    <div class="grid gap-6">
                        <SecretSettingInput v-model="form.feishu_webhook_url" label="Webhook 地址" :disabled="!canUpdate" :configured="settings.feishu_webhook_configured" :error="form.errors.feishu_webhook_url" placeholder="https://open.feishu.cn/open-apis/bot/v2/hook/..." help="留空会保留已保存的地址。" />
                        <SecretSettingInput v-model="form.feishu_secret" label="签名密钥" :disabled="!canUpdate" :configured="settings.feishu_secret_configured" :error="form.errors.feishu_secret" help="未启用签名校验时可以留空；留空会保留已保存的密钥。" />
                    </div>

                    <div class="mt-7 rounded-2xl border border-amber-100 bg-amber-50 px-5 py-4 text-xs leading-5 text-amber-800">
                        当前仅配置一个飞书群机器人；需要增加更多机器人或不同通知规则时再扩展。
                    </div>

                    <div v-if="canUpdate" class="mt-7 flex justify-end">
                        <button :disabled="form.processing" class="rounded-xl bg-slate-950 px-6 py-3 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-50">{{ form.processing ? '保存中…' : '保存飞书设置' }}</button>
                    </div>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
