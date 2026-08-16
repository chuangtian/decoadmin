<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

defineProps<{ callbackUrl: string; configured: boolean }>();

const form = useForm({ name: '', shop_domain: '', environment: 'production' });
const submit = () => form.post('/stores');
</script>

<template>
    <Head title="连接 Shopify 店铺" />
    <AppLayout>
        <div class="mx-auto max-w-3xl">
            <Link href="/stores" class="text-sm font-semibold text-slate-500 transition hover:text-slate-900">← 返回店铺列表</Link>
            <div class="mt-5">
                <p class="text-sm font-semibold text-emerald-700">Shopify OAuth</p>
                <h2 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">连接 Shopify 店铺</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500">填写店铺信息后，将跳转到 Shopify 完成安全授权。当前阶段不会读取订单、商品或客户数据。</p>
            </div>

            <div v-if="!configured" class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">尚未配置 Shopify App Client ID 和 Client Secret，请先完成本地环境变量配置。</div>

            <form class="mt-7 space-y-6" @submit.prevent="submit">
                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                    <div class="grid gap-6">
                        <label class="text-sm font-semibold text-slate-800">店铺名称
                            <input v-model="form.name" required maxlength="120" placeholder="例如：Macfox US" class="mt-2.5 w-full rounded-xl border border-slate-300 px-4 py-3 font-normal outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-50" />
                            <span v-if="form.errors.name" class="mt-1.5 block text-xs font-normal text-rose-600">{{ form.errors.name }}</span>
                        </label>
                        <label class="text-sm font-semibold text-slate-800">Shopify 店铺域名
                            <div class="mt-2.5 flex overflow-hidden rounded-xl border border-slate-300 bg-white focus-within:border-emerald-500 focus-within:ring-4 focus-within:ring-emerald-50">
                                <input v-model="form.shop_domain" required placeholder="example.myshopify.com" class="min-w-0 flex-1 px-4 py-3 font-normal outline-none" />
                            </div>
                            <span v-if="form.errors.shop_domain" class="mt-1.5 block text-xs font-normal text-rose-600">{{ form.errors.shop_domain }}</span>
                            <span v-else class="mt-1.5 block text-xs font-normal text-slate-400">请使用 Shopify 后台显示的永久 myshopify.com 域名。</span>
                        </label>
                        <label class="text-sm font-semibold text-slate-800">Environment
                            <select v-model="form.environment" class="mt-2.5 w-full rounded-xl border border-slate-300 bg-white px-4 py-3 font-normal outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-50">
                                <option value="production">Production</option>
                                <option value="development">Development</option>
                            </select>
                            <span v-if="form.errors.environment" class="mt-1.5 block text-xs font-normal text-rose-600">{{ form.errors.environment }}</span>
                            <span v-else class="mt-1.5 block text-xs font-normal text-slate-400">用于区分正式店铺与开发测试店铺。</span>
                        </label>
                    </div>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-slate-50 p-5 text-sm">
                    <p class="font-semibold text-slate-800">授权回调地址</p>
                    <p class="mt-2 break-all rounded-lg bg-white px-3 py-2 font-mono text-xs text-slate-600 ring-1 ring-slate-200">{{ callbackUrl }}</p>
                    <p class="mt-2 text-xs leading-5 text-slate-500">使用 Cloudflare Tunnel 联调时，请将 HTTPS 回调地址加入 Shopify App 的 Allowed redirection URL(s)。</p>
                </section>

                <div class="flex justify-end gap-3">
                    <Link href="/stores" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700">取消</Link>
                    <button :disabled="form.processing || !configured" class="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50">{{ form.processing ? '正在跳转…' : 'Connect Shopify' }}</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
