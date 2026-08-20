<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import SecretSettingInput from '../../../Components/Settings/SecretSettingInput.vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

interface MailSettings {
    mail_enabled: boolean;
    mail_host: string;
    mail_port: number;
    mail_encryption: string;
    mail_username: string;
    mail_password: string;
    mail_password_configured: boolean;
    mail_from_address: string;
    mail_from_name: string;
    notification_email: string;
}

const props = defineProps<{
    store: { id: number; name: string; shopify_domain: string };
    settings: MailSettings;
    canUpdate: boolean;
}>();
const form = useForm({
    mail_enabled: props.settings.mail_enabled,
    mail_host: props.settings.mail_host,
    mail_port: props.settings.mail_port,
    mail_encryption: props.settings.mail_encryption,
    mail_username: props.settings.mail_username,
    mail_password: props.settings.mail_password,
    mail_from_address: props.settings.mail_from_address,
    mail_from_name: props.settings.mail_from_name,
    notification_email: props.settings.notification_email,
});
const inputClass = 'mt-2 w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm outline-none transition focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 disabled:cursor-not-allowed disabled:bg-slate-50';
const toggleSaving = ref(false);
const save = () => form.put('/store-settings/mail', { preserveScroll: true });
const toggleMail = (event: Event) => {
    const enabled = (event.target as HTMLInputElement).checked;
    form.mail_enabled = enabled;
    toggleSaving.value = true;
    router.patch('/store-settings/mail/enabled', { enabled }, {
        preserveScroll: true,
        onError: () => { form.mail_enabled = !enabled; },
        onFinish: () => { toggleSaving.value = false; },
    });
};
</script>

<template>
    <Head :title="`${store.name} 邮箱设置`" />
    <AppLayout :breadcrumbs="[{ label: '店铺设置' }, { label: '邮箱设置' }]">
        <div class="mx-auto max-w-5xl">
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <header class="border-b border-slate-100 px-5 py-5 sm:px-7">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">店铺独立邮件通道</p>
                    <h2 class="mt-1 text-xl font-semibold text-slate-950">邮箱设置</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-500">此配置只属于当前店铺，不会继承或覆盖系统邮箱。</p>
                </header>

                <form class="p-5 sm:p-7" @submit.prevent="save">
                    <div class="mb-7 flex items-start justify-between gap-4 rounded-2xl bg-emerald-50 p-4 ring-1 ring-emerald-100">
                        <div>
                            <h3 class="text-sm font-semibold text-emerald-950">启用店铺邮箱</h3>
                            <p class="mt-1 text-xs leading-5 text-emerald-700">启用并配置完成后，当前店铺的异常告警将从此邮箱发出。</p>
                        </div>
                        <label class="relative inline-flex shrink-0 cursor-pointer items-center">
                            <input v-model="form.mail_enabled" :disabled="!canUpdate || toggleSaving" type="checkbox" class="peer sr-only" @change="toggleMail($event)" />
                            <span class="h-6 w-11 rounded-full bg-slate-300 transition peer-checked:bg-emerald-500 peer-disabled:opacity-50 after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:transition peer-checked:after:translate-x-5" />
                        </label>
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <label>
                            <span class="text-sm font-semibold text-slate-800">SMTP 服务器</span>
                            <input v-model="form.mail_host" :disabled="!canUpdate" placeholder="smtp.gmail.com" :class="inputClass" />
                            <span v-if="form.errors.mail_host" class="mt-1.5 block text-xs text-rose-600">{{ form.errors.mail_host }}</span>
                        </label>
                        <div class="grid grid-cols-2 gap-3">
                            <label><span class="text-sm font-semibold text-slate-800">端口</span><input v-model="form.mail_port" :disabled="!canUpdate" type="number" min="1" max="65535" :class="inputClass" /></label>
                            <label><span class="text-sm font-semibold text-slate-800">加密方式</span><select v-model="form.mail_encryption" :disabled="!canUpdate" :class="inputClass"><option value="tls">TLS</option><option value="ssl">SSL</option><option value="none">不加密</option></select></label>
                        </div>
                        <label><span class="text-sm font-semibold text-slate-800">登录账号</span><input v-model="form.mail_username" :disabled="!canUpdate" autocomplete="off" :class="inputClass" /></label>
                        <SecretSettingInput v-model="form.mail_password" label="邮箱密码 / 授权码" :disabled="!canUpdate" :configured="settings.mail_password_configured" :error="form.errors.mail_password" help="留空会保留已保存的密码。" />
                        <label><span class="text-sm font-semibold text-slate-800">发件邮箱</span><input v-model="form.mail_from_address" :disabled="!canUpdate" type="email" placeholder="store@example.com" :class="inputClass" /><span v-if="form.errors.mail_from_address" class="mt-1.5 block text-xs text-rose-600">{{ form.errors.mail_from_address }}</span></label>
                        <label><span class="text-sm font-semibold text-slate-800">发件名称</span><input v-model="form.mail_from_name" :disabled="!canUpdate" :placeholder="store.name" :class="inputClass" /></label>
                    </div>

                    <div class="mt-7 rounded-2xl border border-blue-100 bg-blue-50/70 p-5">
                        <label class="block">
                            <span class="text-sm font-semibold text-blue-950">通知邮箱</span>
                            <p class="mt-1 text-xs leading-5 text-blue-700">店铺同步、Webhook 或连接发生异常时，告警将发送到此邮箱。</p>
                            <input v-model="form.notification_email" :disabled="!canUpdate" type="email" placeholder="notify@example.com" :class="inputClass" />
                            <span v-if="form.errors.notification_email" class="mt-1.5 block text-xs text-rose-600">{{ form.errors.notification_email }}</span>
                        </label>
                    </div>

                    <div v-if="canUpdate" class="mt-7 flex justify-end">
                        <button :disabled="form.processing" class="rounded-xl bg-slate-950 px-6 py-3 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-50">{{ form.processing ? '保存中…' : '保存邮箱设置' }}</button>
                    </div>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
