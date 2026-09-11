<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import SecretSettingInput from '../Settings/SecretSettingInput.vue';

export interface MetaCredentialSettings {
    instagram_app_id: string;
    instagram_app_secret: string;
    facebook_app_id: string;
    facebook_app_secret: string;
    facebook_login_config_id: string;
    instagram_app_secret_configured: boolean;
    facebook_app_secret_configured: boolean;
}

export interface R2CredentialSettings {
    account_id: string;
    access_key_id: string;
    secret_access_key: string;
    bucket: string;
    public_base_url: string;
    secret_access_key_configured: boolean;
}

export interface AppCredentials {
    can_update: boolean;
    meta: MetaCredentialSettings;
    r2: R2CredentialSettings;
    callbacks: { instagram: string; facebook: string };
    endpoints: { meta: string; r2: string };
}

const props = defineProps<{ credentials: AppCredentials; environment: string }>();

const canUpdate = computed(() => props.credentials.can_update);

const metaForm = useForm({
    instagram_app_id: props.credentials.meta.instagram_app_id,
    instagram_app_secret: '',
    facebook_app_id: props.credentials.meta.facebook_app_id,
    facebook_app_secret: '',
    facebook_login_config_id: props.credentials.meta.facebook_login_config_id,
});

const r2Form = useForm({
    account_id: props.credentials.r2.account_id,
    access_key_id: props.credentials.r2.access_key_id,
    secret_access_key: '',
    bucket: props.credentials.r2.bucket,
    public_base_url: props.credentials.r2.public_base_url,
});

const instagramReady = computed(() => Boolean(props.credentials.meta.instagram_app_id) && props.credentials.meta.instagram_app_secret_configured);
const facebookReady = computed(() => Boolean(props.credentials.meta.facebook_app_id) && props.credentials.meta.facebook_app_secret_configured);
const r2Ready = computed(() => Boolean(props.credentials.r2.account_id)
    && Boolean(props.credentials.r2.access_key_id)
    && Boolean(props.credentials.r2.bucket)
    && Boolean(props.credentials.r2.public_base_url)
    && props.credentials.r2.secret_access_key_configured);

const secretPlaceholder = (configured: boolean) => configured ? '已保存，留空则保持不变' : '粘贴新的密钥';

// 保存后停在当前页签，所以保留组件状态；密钥输入框清空避免留在内存里。
const saveMeta = () => metaForm.put(props.credentials.endpoints.meta, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => metaForm.reset('instagram_app_secret', 'facebook_app_secret'),
});

const saveR2 = () => r2Form.put(props.credentials.endpoints.r2, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => r2Form.reset('secret_access_key'),
});
</script>

<template>
    <div class="space-y-6">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 text-sm leading-6 text-slate-500">
            这里维护的是 Instagram Feed 应用本身的凭证，属于平台级配置，对所有店铺共享，需要系统设置权限。保存后立即生效，不需要改服务器环境变量或重启容器；留空的项会回退到当前环境（{{ environment }}）的 .env 值。
        </div>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-5 sm:px-6">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">Meta 开发者平台</p>
                    <h2 class="mt-1 text-lg font-semibold text-slate-950">Instagram / Facebook 应用凭证</h2>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="instagramReady ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-amber-50 text-amber-700 ring-amber-200'">Instagram 登录 {{ instagramReady ? '已就绪' : '未配置' }}</span>
                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="facebookReady ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-amber-50 text-amber-700 ring-amber-200'">Facebook 登录 {{ facebookReady ? '已就绪' : '未配置' }}</span>
                </div>
            </header>

            <form class="p-5 sm:p-6" @submit.prevent="saveMeta">
                <div class="mb-6 rounded-2xl bg-sky-50 p-4 ring-1 ring-sky-100">
                    <h3 class="text-sm font-semibold text-sky-950">两条授权链路可以只配一条</h3>
                    <p class="mt-1 text-xs leading-5 text-sky-700">Instagram 登录用于商家直接授权 Instagram 专业账号；Facebook 登录用于经 Facebook 主页绑定的 Instagram 账号。任一条链路凭证缺失时，对应的连接按钮自动隐藏，不影响另一条。</p>
                    <dl class="mt-3 grid gap-2 text-xs text-sky-900 sm:grid-cols-2">
                        <div>
                            <dt class="font-semibold">Instagram 回调地址</dt>
                            <dd class="mt-0.5 truncate font-mono text-xs text-sky-700" :title="credentials.callbacks.instagram">{{ credentials.callbacks.instagram }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold">Facebook 回调地址</dt>
                            <dd class="mt-0.5 truncate font-mono text-xs text-sky-700" :title="credentials.callbacks.facebook">{{ credentials.callbacks.facebook }}</dd>
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
                        :configured="credentials.meta.instagram_app_secret_configured"
                        :error="metaForm.errors.instagram_app_secret"
                        :placeholder="secretPlaceholder(credentials.meta.instagram_app_secret_configured)"
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
                        :configured="credentials.meta.facebook_app_secret_configured"
                        :error="metaForm.errors.facebook_app_secret"
                        :placeholder="secretPlaceholder(credentials.meta.facebook_app_secret_configured)"
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

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-5 sm:px-6">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">对象存储</p>
                    <h2 class="mt-1 text-lg font-semibold text-slate-950">Cloudflare R2</h2>
                </div>
                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1" :class="r2Ready ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-amber-50 text-amber-700 ring-amber-200'">{{ r2Ready ? '已就绪' : '未配置' }}</span>
            </header>

            <form class="p-5 sm:p-6" @submit.prevent="saveR2">
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
                        :configured="credentials.r2.secret_access_key_configured"
                        :error="r2Form.errors.secret_access_key"
                        :placeholder="secretPlaceholder(credentials.r2.secret_access_key_configured)"
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

        <p class="text-xs leading-6 text-slate-400">
            密钥会用应用密钥加密后保存在数据库，保存后不再回显；审计日志只记录被修改的字段名，不记录密钥内容。
        </p>
    </div>
</template>
