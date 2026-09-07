<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import SecretSettingInput from '../../Components/Settings/SecretSettingInput.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

interface MetaSettings {
    instagram_app_id: string;
    instagram_app_secret: string;
    facebook_app_id: string;
    facebook_app_secret: string;
    facebook_login_config_id: string;
    instagram_app_secret_configured: boolean;
    facebook_app_secret_configured: boolean;
}

interface R2Settings {
    account_id: string;
    access_key_id: string;
    secret_access_key: string;
    bucket: string;
    public_base_url: string;
    secret_access_key_configured: boolean;
}

const props = defineProps<{
    metaSettings: MetaSettings;
    r2Settings: R2Settings;
    canUpdate: boolean;
    environment: string;
    callbacks: { instagram: string; facebook: string };
}>();

const metaForm = useForm({
    instagram_app_id: props.metaSettings.instagram_app_id,
    instagram_app_secret: '',
    facebook_app_id: props.metaSettings.facebook_app_id,
    facebook_app_secret: '',
    facebook_login_config_id: props.metaSettings.facebook_login_config_id,
});

const r2Form = useForm({
    account_id: props.r2Settings.account_id,
    access_key_id: props.r2Settings.access_key_id,
    secret_access_key: '',
    bucket: props.r2Settings.bucket,
    public_base_url: props.r2Settings.public_base_url,
});

const environmentLabel = computed(() => ({
    local: '本地开发',
    test: '测试服',
    production: '正式服',
}[props.environment] ?? props.environment));

const instagramReady = computed(() => Boolean(props.metaSettings.instagram_app_id) && props.metaSettings.instagram_app_secret_configured);
const facebookReady = computed(() => Boolean(props.metaSettings.facebook_app_id) && props.metaSettings.facebook_app_secret_configured);
const r2Ready = computed(() => Boolean(props.r2Settings.account_id)
    && Boolean(props.r2Settings.access_key_id)
    && Boolean(props.r2Settings.bucket)
    && Boolean(props.r2Settings.public_base_url)
    && props.r2Settings.secret_access_key_configured);

const secretPlaceholder = (configured: boolean) => configured ? '已保存，留空则保持不变' : '粘贴新的密钥';

const saveMeta = () => metaForm.put('/settings/instagram-feed/meta', {
    preserveScroll: true,
    onSuccess: () => metaForm.reset('instagram_app_secret', 'facebook_app_secret'),
});

const saveR2 = () => r2Form.put('/settings/instagram-feed/r2', {
    preserveScroll: true,
    onSuccess: () => r2Form.reset('secret_access_key'),
});
</script>

<template>
    <Head title="Instagram 与存储设置" />
    <AppLayout :breadcrumbs="[{ label: '系统管理' }, { label: 'Instagram 与存储' }]">
        <div class="mx-auto max-w-5xl">
            <section class="mb-7 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold text-emerald-700">系统管理</p>
                    <h1 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">Instagram 与存储</h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-500">
                        在后台维护 Instagram / Facebook 应用凭证和 Cloudflare R2 存储配置。保存后立即生效，无需改动 .env 或重启容器；未在此配置的项仍回退到当前环境的 .env 值。
                    </p>
                </div>
                <div class="flex flex-col items-start gap-2 lg:items-end">
                    <span class="inline-flex rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700 ring-1 ring-slate-200">当前环境：{{ environmentLabel }}</span>
                    <span class="inline-flex rounded-full px-3 py-1.5 text-xs font-semibold ring-1" :class="canUpdate ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-slate-200'">{{ canUpdate ? '可编辑' : '只读查看' }}</span>
                </div>
            </section>

            <section class="mb-7 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-5 sm:px-7">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">Meta 开发者平台</p>
                        <h2 class="mt-1 text-xl font-semibold text-slate-950">Instagram / Facebook 应用凭证</h2>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span class="inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1" :class="instagramReady ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-amber-50 text-amber-700 ring-amber-200'">Instagram 登录 {{ instagramReady ? '已就绪' : '未配置' }}</span>
                        <span class="inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1" :class="facebookReady ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-amber-50 text-amber-700 ring-amber-200'">Facebook 登录 {{ facebookReady ? '已就绪' : '未配置' }}</span>
                    </div>
                </header>

                <form class="p-5 sm:p-7" @submit.prevent="saveMeta">
                    <div class="mb-6 rounded-2xl bg-sky-50 p-4 ring-1 ring-sky-100">
                        <h3 class="text-sm font-semibold text-sky-950">两条授权链路可以只配一条</h3>
                        <p class="mt-1 text-xs leading-5 text-sky-700">Instagram 登录用于商家直接授权 Instagram 专业账号；Facebook 登录用于经 Facebook 主页绑定的 Instagram 账号。任一条链路凭证缺失时，对应授权入口自动不可用，不影响另一条。</p>
                        <dl class="mt-3 grid gap-2 text-xs text-sky-900 sm:grid-cols-2">
                            <div>
                                <dt class="font-semibold">Instagram 回调地址</dt>
                                <dd class="mt-0.5 truncate font-mono text-[11px] text-sky-700" :title="callbacks.instagram">{{ callbacks.instagram }}</dd>
                            </div>
                            <div>
                                <dt class="font-semibold">Facebook 回调地址</dt>
                                <dd class="mt-0.5 truncate font-mono text-[11px] text-sky-700" :title="callbacks.facebook">{{ callbacks.facebook }}</dd>
                            </div>
                        </dl>
                        <p class="mt-2 text-xs leading-5 text-sky-700">回调地址必须与 Meta 后台 OAuth 设置完全一致，包括协议和结尾斜杠。</p>
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-semibold text-slate-800">Instagram App ID</span>
                            <input v-model="metaForm.instagram_app_id" :disabled="!canUpdate" autocomplete="off" placeholder="Meta 应用 → Instagram → API 设置" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 font-mono text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 disabled:bg-slate-50" />
                            <span v-if="metaForm.errors.instagram_app_id" class="mt-1 block text-xs text-rose-600">{{ metaForm.errors.instagram_app_id }}</span>
                        </label>
                        <SecretSettingInput
                            v-model="metaForm.instagram_app_secret"
                            label="Instagram App Secret"
                            :disabled="!canUpdate"
                            :configured="metaSettings.instagram_app_secret_configured"
                            :error="metaForm.errors.instagram_app_secret"
                            :placeholder="secretPlaceholder(metaSettings.instagram_app_secret_configured)"
                        />
                        <label class="block">
                            <span class="text-sm font-semibold text-slate-800">Facebook App ID</span>
                            <input v-model="metaForm.facebook_app_id" :disabled="!canUpdate" autocomplete="off" placeholder="Meta 应用 → 设置 → 基本" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 font-mono text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 disabled:bg-slate-50" />
                            <span v-if="metaForm.errors.facebook_app_id" class="mt-1 block text-xs text-rose-600">{{ metaForm.errors.facebook_app_id }}</span>
                        </label>
                        <SecretSettingInput
                            v-model="metaForm.facebook_app_secret"
                            label="Facebook App Secret"
                            :disabled="!canUpdate"
                            :configured="metaSettings.facebook_app_secret_configured"
                            :error="metaForm.errors.facebook_app_secret"
                            :placeholder="secretPlaceholder(metaSettings.facebook_app_secret_configured)"
                        />
                        <label class="block sm:col-span-2">
                            <span class="text-sm font-semibold text-slate-800">Facebook 登录配置 ID<span class="ml-2 text-xs font-normal text-slate-400">可选</span></span>
                            <input v-model="metaForm.facebook_login_config_id" :disabled="!canUpdate" autocomplete="off" inputmode="numeric" placeholder="Facebook Login for Business 的配置化登录 ID" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 font-mono text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 disabled:bg-slate-50" />
                            <span v-if="metaForm.errors.facebook_login_config_id" class="mt-1 block text-xs text-rose-600">{{ metaForm.errors.facebook_login_config_id }}</span>
                            <span v-else class="mt-1.5 block text-xs leading-5 text-slate-400">填写后 Facebook 授权走配置化登录，不再单独传 scope。</span>
                        </label>
                    </div>

                    <div v-if="canUpdate" class="mt-7 flex justify-end">
                        <button :disabled="metaForm.processing" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-50">{{ metaForm.processing ? '保存中…' : '保存应用凭证' }}</button>
                    </div>
                </form>
            </section>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-5 sm:px-7">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">对象存储</p>
                        <h2 class="mt-1 text-xl font-semibold text-slate-950">Cloudflare R2</h2>
                    </div>
                    <span class="inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1" :class="r2Ready ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-amber-50 text-amber-700 ring-amber-200'">{{ r2Ready ? '已就绪' : '未配置' }}</span>
                </header>

                <form class="p-5 sm:p-7" @submit.prevent="saveR2">
                    <div class="mb-6 rounded-2xl bg-amber-50 p-4 ring-1 ring-amber-100">
                        <h3 class="text-sm font-semibold text-amber-950">未配置时同步会降级</h3>
                        <p class="mt-1 text-xs leading-5 text-amber-800">Instagram CDN 链接带签名会过期，因此视频与封面会转存到 R2，并用绑定在桶上的自定义域名对外提供永久地址。五项全部填写后转存才会启动；缺失时同步只拉取元数据，未转存的内容不会发布到前台。</p>
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-semibold text-slate-800">Account ID</span>
                            <input v-model="r2Form.account_id" :disabled="!canUpdate" autocomplete="off" placeholder="Cloudflare 账号 ID" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 font-mono text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 disabled:bg-slate-50" />
                            <span v-if="r2Form.errors.account_id" class="mt-1 block text-xs text-rose-600">{{ r2Form.errors.account_id }}</span>
                        </label>
                        <label class="block">
                            <span class="text-sm font-semibold text-slate-800">Access Key ID</span>
                            <input v-model="r2Form.access_key_id" :disabled="!canUpdate" autocomplete="off" placeholder="R2 API 令牌的 Access Key ID" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 font-mono text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 disabled:bg-slate-50" />
                            <span v-if="r2Form.errors.access_key_id" class="mt-1 block text-xs text-rose-600">{{ r2Form.errors.access_key_id }}</span>
                        </label>
                        <SecretSettingInput
                            v-model="r2Form.secret_access_key"
                            label="Secret Access Key"
                            :disabled="!canUpdate"
                            :configured="r2Settings.secret_access_key_configured"
                            :error="r2Form.errors.secret_access_key"
                            :placeholder="secretPlaceholder(r2Settings.secret_access_key_configured)"
                        />
                        <label class="block">
                            <span class="text-sm font-semibold text-slate-800">存储桶</span>
                            <input v-model="r2Form.bucket" :disabled="!canUpdate" autocomplete="off" placeholder="例如：deco-instagram-media" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 font-mono text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 disabled:bg-slate-50" />
                            <span v-if="r2Form.errors.bucket" class="mt-1 block text-xs text-rose-600">{{ r2Form.errors.bucket }}</span>
                        </label>
                        <label class="block sm:col-span-2">
                            <span class="text-sm font-semibold text-slate-800">公开访问域名</span>
                            <input v-model="r2Form.public_base_url" :disabled="!canUpdate" autocomplete="off" placeholder="https://media.example.com" class="mt-2 w-full rounded-xl border border-slate-200 px-3.5 py-2.5 font-mono text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100 disabled:bg-slate-50" />
                            <span v-if="r2Form.errors.public_base_url" class="mt-1 block text-xs text-rose-600">{{ r2Form.errors.public_base_url }}</span>
                            <span v-else class="mt-1.5 block text-xs leading-5 text-slate-400">必须是绑定在该桶上的 HTTPS 自定义域名，结尾不需要斜杠。</span>
                        </label>
                    </div>

                    <div v-if="canUpdate" class="mt-7 flex justify-end">
                        <button :disabled="r2Form.processing" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-50">{{ r2Form.processing ? '保存中…' : '保存 R2 配置' }}</button>
                    </div>
                </form>
            </section>

            <p class="mt-5 text-xs leading-6 text-slate-400">
                密钥会用应用密钥加密后保存在数据库，保存后不再回显；审计日志只记录被修改的字段名，不记录密钥内容。
            </p>
        </div>
    </AppLayout>
</template>
