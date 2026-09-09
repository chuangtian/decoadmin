<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import SecretSettingInput from '../../Components/Settings/SecretSettingInput.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

interface Settings {
    mail_enabled:boolean; mail_host:string; mail_port:number; mail_encryption:string;
    mail_username:string; mail_password:string; mail_password_configured:boolean;
    mail_from_address:string; mail_from_name:string; mail_recipients:string[];
    feishu_enabled:boolean; feishu_webhook_url:string; feishu_webhook_configured:boolean;
    feishu_secret:string; feishu_secret_configured:boolean; notify_sync_failed:boolean;
    notify_webhook_failed:boolean; notify_connection_unhealthy:boolean; notify_discount_monitor:boolean; notify_product_monitor:boolean;
}
const props=defineProps<{store:{id:number;name:string;shopify_domain:string};settings:Settings;canUpdate:boolean}>();
const recipients=ref(props.settings.mail_recipients.join('\n'));
const form=useForm({...props.settings,mail_recipients:props.settings.mail_recipients});
const field='mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 disabled:bg-slate-50';
const submit=()=>{form.mail_recipients=recipients.value.split(/[\n,;]/).map(v=>v.trim()).filter(Boolean);form.put(`/stores/${props.store.id}/notifications`,{preserveScroll:true});};
</script>

<template>
    <Head :title="`${store.name} 通知设置`"/>
    <AppLayout :breadcrumbs="[{label:'Shopify'},{label:'店铺管理',href:'/stores'},{label:store.name,href:`/stores/${store.id}`},{label:'通知设置'}]">
        <div class="mx-auto max-w-6xl space-y-6">
            <Link :href="`/stores/${store.id}`" class="text-sm font-semibold text-slate-500">← 返回店铺详情</Link>
            <header><p class="text-sm font-semibold text-emerald-700">店铺级通知</p><h1 class="mt-1 text-3xl font-semibold text-slate-950">{{store.name}} 通知设置</h1><p class="mt-2 text-sm text-slate-500">默认关闭；只有启用并完成配置后才发送，不会影响系统级邮箱和飞书设置。</p></header>
            <form class="space-y-6" @submit.prevent="submit">
                <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                    <div class="flex items-center justify-between"><div><h2 class="text-lg font-semibold">店铺邮箱</h2><p class="mt-1 text-sm text-slate-500">使用此店铺独立 SMTP 发送异常告警。</p></div><label class="flex items-center gap-2 text-sm font-semibold"><input v-model="form.mail_enabled" type="checkbox" :disabled="!canUpdate"/>启用</label></div>
                    <div class="mt-6 grid gap-5 md:grid-cols-2">
                        <label><span class="text-sm font-semibold">SMTP 主机</span><input v-model="form.mail_host" :class="field" :disabled="!canUpdate" placeholder="smtp.gmail.com"/></label>
                        <div class="grid grid-cols-2 gap-3"><label><span class="text-sm font-semibold">端口</span><input v-model="form.mail_port" type="number" :class="field" :disabled="!canUpdate"/></label><label><span class="text-sm font-semibold">加密</span><select v-model="form.mail_encryption" :class="field" :disabled="!canUpdate"><option value="tls">TLS</option><option value="ssl">SSL</option><option value="none">无</option></select></label></div>
                        <label><span class="text-sm font-semibold">账号</span><input v-model="form.mail_username" :class="field" :disabled="!canUpdate"/></label>
                        <SecretSettingInput v-model="form.mail_password" label="SMTP 密码" :configured="settings.mail_password_configured" :disabled="!canUpdate" help="留空会保留已保存的密码。"/>
                        <label><span class="text-sm font-semibold">发件邮箱</span><input v-model="form.mail_from_address" type="email" :class="field" :disabled="!canUpdate"/></label>
                        <label><span class="text-sm font-semibold">发件名称</span><input v-model="form.mail_from_name" :class="field" :disabled="!canUpdate"/></label>
                        <label class="md:col-span-2"><span class="text-sm font-semibold">收件邮箱</span><textarea v-model="recipients" rows="3" :class="field" :disabled="!canUpdate" placeholder="每行填写一个邮箱"/></label>
                    </div>
                </section>
                <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                    <div class="flex items-center justify-between"><div><h2 class="text-lg font-semibold">飞书机器人</h2><p class="mt-1 text-sm text-slate-500">将此店铺异常发送到指定飞书群。</p></div><label class="flex items-center gap-2 text-sm font-semibold"><input v-model="form.feishu_enabled" type="checkbox" :disabled="!canUpdate"/>启用</label></div>
                    <div class="mt-6 grid gap-5"><SecretSettingInput v-model="form.feishu_webhook_url" label="Webhook 地址" :configured="settings.feishu_webhook_configured" :disabled="!canUpdate" help="留空会保留已保存地址。"/><SecretSettingInput v-model="form.feishu_secret" label="签名密钥" :configured="settings.feishu_secret_configured" :disabled="!canUpdate" help="没有启用签名校验时可留空。"/></div>
                </section>
                <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8"><h2 class="text-lg font-semibold">通知事件</h2><div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"><label class="flex gap-3 rounded-2xl border p-4 text-sm font-semibold"><input v-model="form.notify_connection_unhealthy" type="checkbox" :disabled="!canUpdate"/>连接异常</label><label class="flex gap-3 rounded-2xl border p-4 text-sm font-semibold"><input v-model="form.notify_sync_failed" type="checkbox" :disabled="!canUpdate"/>同步失败</label><label class="flex gap-3 rounded-2xl border p-4 text-sm font-semibold"><input v-model="form.notify_webhook_failed" type="checkbox" :disabled="!canUpdate"/>Webhook 失败</label><label class="flex gap-3 rounded-2xl border p-4 text-sm font-semibold"><input v-model="form.notify_discount_monitor" type="checkbox" :disabled="!canUpdate"/>重点折扣预警</label><label class="flex gap-3 rounded-2xl border p-4 text-sm font-semibold"><input v-model="form.notify_product_monitor" type="checkbox" :disabled="!canUpdate"/>重点产品库存与状态预警</label></div></section>
                <div v-if="canUpdate" class="flex justify-end"><button :disabled="form.processing" class="rounded-xl bg-slate-950 px-6 py-3 text-sm font-semibold text-white disabled:opacity-50">保存店铺通知设置</button></div>
            </form>
        </div>
    </AppLayout>
</template>
