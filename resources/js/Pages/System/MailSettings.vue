<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import SecretSettingInput from '../../Components/Settings/SecretSettingInput.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

interface MailSettings {
    enabled: boolean;
    host: string;
    port: number;
    encryption: string;
    username: string;
    password: string;
    from_address: string;
    from_name: string;
    timeout: number;
    password_configured: boolean;
}

const props = defineProps<{ settings: MailSettings; canUpdate: boolean }>();
const form = useForm({
    enabled: props.settings.enabled,
    host: props.settings.host,
    port: props.settings.port,
    encryption: props.settings.encryption,
    username: props.settings.username,
    password: props.settings.password,
    from_address: props.settings.from_address,
    from_name: props.settings.from_name,
    timeout: props.settings.timeout,
});
const save = () => form.put('/settings/mail', { preserveScroll: true });
</script>

<template>
    <Head title="邮箱设置" />
    <AppLayout :breadcrumbs="[{ label: '系统管理' }, { label: '邮箱设置' }]">
        <div class="mx-auto max-w-5xl">
            <section class="mb-7 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold text-emerald-700">系统管理</p>
                    <h1 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">邮箱设置</h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">配置登录验证、密码重置和系统告警使用的平台发信邮箱。</p>
                </div>
                <span class="inline-flex self-start rounded-full px-3 py-1.5 text-xs font-semibold ring-1" :class="canUpdate ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-slate-200'">{{ canUpdate ? '可编辑' : '只读查看' }}</span>
            </section>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <header class="border-b border-slate-100 px-5 py-5 sm:px-7">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">平台通知与账号邮件</p>
                    <h2 class="mt-1 text-xl font-semibold text-slate-950">系统邮箱</h2>
                </header>

                <form class="p-5 sm:p-7" @submit.prevent="save">
                    <div class="mb-6 flex items-start justify-between gap-4 rounded-2xl bg-blue-50 p-4 ring-1 ring-blue-100">
                        <div>
                            <h3 class="text-sm font-semibold text-blue-950">平台系统发信邮箱</h3>
                            <p class="mt-1 text-xs leading-5 text-blue-700">这里只负责平台邮件。订单、营销和店铺客服邮箱以后在每个店铺中单独配置，不会继承或覆盖这里的设置。</p>
                        </div>
                        <label class="relative inline-flex shrink-0 cursor-pointer items-center">
                            <input v-model="form.enabled" :disabled="!canUpdate" type="checkbox" class="peer sr-only" />
                            <span class="h-6 w-11 rounded-full bg-slate-300 transition peer-checked:bg-emerald-500 peer-disabled:opacity-50 after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:transition peer-checked:after:translate-x-5" />
                        </label>
                    </div>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <label>
                            <span class="text-sm font-semibold text-slate-800">SMTP 服务器</span>
                            <input v-model="form.host" :disabled="!canUpdate" placeholder="smtp.example.com" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 disabled:bg-slate-50" />
                            <span v-if="form.errors.host" class="mt-1 block text-xs text-rose-600">{{ form.errors.host }}</span>
                        </label>
                        <div class="grid grid-cols-2 gap-3">
                            <label><span class="text-sm font-semibold text-slate-800">端口</span><input v-model="form.port" :disabled="!canUpdate" type="number" min="1" max="65535" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 disabled:bg-slate-50" /></label>
                            <label><span class="text-sm font-semibold text-slate-800">加密方式</span><select v-model="form.encryption" :disabled="!canUpdate" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm outline-none focus:border-emerald-500 disabled:bg-slate-50"><option value="tls">TLS</option><option value="ssl">SSL</option><option value="none">不加密</option></select></label>
                        </div>
                        <label><span class="text-sm font-semibold text-slate-800">登录账号</span><input v-model="form.username" :disabled="!canUpdate" autocomplete="off" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 disabled:bg-slate-50" /></label>
                        <SecretSettingInput v-model="form.password" label="邮箱密码 / 授权码" :disabled="!canUpdate" :configured="settings.password_configured" :error="form.errors.password" placeholder="输入 SMTP 密码或授权码" />
                        <label><span class="text-sm font-semibold text-slate-800">发件人邮箱</span><input v-model="form.from_address" :disabled="!canUpdate" type="email" placeholder="system@example.com" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 disabled:bg-slate-50" /><span v-if="form.errors.from_address" class="mt-1 block text-xs text-rose-600">{{ form.errors.from_address }}</span></label>
                        <label><span class="text-sm font-semibold text-slate-800">发件人名称</span><input v-model="form.from_name" :disabled="!canUpdate" placeholder="DecoAdmin 系统" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 disabled:bg-slate-50" /></label>
                        <label><span class="text-sm font-semibold text-slate-800">连接超时（秒）</span><input v-model="form.timeout" :disabled="!canUpdate" type="number" min="1" max="60" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm outline-none focus:border-emerald-500 disabled:bg-slate-50" /></label>
                    </div>
                    <div v-if="canUpdate" class="mt-7 flex justify-end"><button :disabled="form.processing" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-50">{{ form.processing ? '保存中…' : '保存邮箱设置' }}</button></div>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
