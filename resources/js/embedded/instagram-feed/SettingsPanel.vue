<script setup lang="ts">
/**
 * 「应用配置」页签：本店铺自己的 Meta 应用凭证与 Cloudflare R2 存储凭证。
 *
 * 两张卡片各自独立保存，互不影响 —— 商家常常只想改一组。密钥从不回显，
 * 留空即保持原值，所以每次保存成功后把密钥输入框清空，不留在内存里。
 */
import { computed, onMounted, ref } from 'vue';
import { ApiError, type ApiClient } from './api';
import SecretField from './SecretField.vue';
import type { StoreSettings } from './types';

const props = defineProps<{
    api: ApiClient;
    busy: boolean;
    environment: string;
    run: (action: () => Promise<{ message: string }>, refresh?: () => Promise<void>) => Promise<void>;
}>();

const settings = ref<StoreSettings | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);

// 校验错误按字段绑定。后端返回 Laravel 的 422 errors，api.ts 已经塞进 ApiError.validation。
const metaErrors = ref<Record<string, string[]>>({});
const r2Errors = ref<Record<string, string[]>>({});

const metaForm = ref({
    instagram_app_id: '',
    instagram_app_secret: '',
    facebook_app_id: '',
    facebook_app_secret: '',
    facebook_login_config_id: '',
});

const r2Form = ref({
    account_id: '',
    access_key_id: '',
    secret_access_key: '',
    bucket: '',
    public_base_url: '',
});

const hydrate = (data: StoreSettings) => {
    settings.value = data;
    metaForm.value = {
        instagram_app_id: data.meta.instagram_app_id,
        instagram_app_secret: '',
        facebook_app_id: data.meta.facebook_app_id,
        facebook_app_secret: '',
        facebook_login_config_id: data.meta.facebook_login_config_id,
    };
    r2Form.value = {
        account_id: data.r2.account_id,
        access_key_id: data.r2.access_key_id,
        secret_access_key: '',
        bucket: data.r2.bucket,
        public_base_url: data.r2.public_base_url,
    };
};

const load = async () => {
    try {
        hydrate(await props.api.get<StoreSettings>('/settings'));
        loadError.value = null;
    } catch (error) {
        loadError.value = error instanceof ApiError ? error.message : '读取应用配置失败，请稍后重试。';
    } finally {
        loading.value = false;
    }
};

onMounted(load);

const instagramReady = computed(() => Boolean(settings.value?.meta.instagram_app_id)
    && Boolean(settings.value?.meta.instagram_app_secret_configured));
const facebookReady = computed(() => Boolean(settings.value?.meta.facebook_app_id)
    && Boolean(settings.value?.meta.facebook_app_secret_configured));
const r2Ready = computed(() => Boolean(settings.value?.r2.account_id)
    && Boolean(settings.value?.r2.access_key_id)
    && Boolean(settings.value?.r2.bucket)
    && Boolean(settings.value?.r2.public_base_url)
    && Boolean(settings.value?.r2.secret_access_key_configured));

const secretPlaceholder = (configured: boolean) => (configured ? '已保存，留空则保持不变' : '粘贴新的密钥');

const sourceNote = (source: 'store' | 'platform') => (source === 'store'
    ? '当前用的是本店铺自己的配置。'
    : '本店铺还没有单独配置，当前用的是平台默认值。填写并保存后即改为只对本店铺生效。');

const saving = ref<'meta' | 'r2' | null>(null);

/**
 * run() 会吞掉异常并统一弹提示，但字段级校验明细拿不到，
 * 所以在传给它的动作里先把 ApiError.validation 截下来再往外抛。
 */
const submit = async (section: 'meta' | 'r2') => {
    const errors = section === 'meta' ? metaErrors : r2Errors;
    errors.value = {};
    saving.value = section;
    try {
        await props.run(async () => {
            try {
                return await props.api.put<{ message: string }>(
                    `/settings/${section}`,
                    section === 'meta' ? metaForm.value : r2Form.value,
                );
            } catch (error) {
                if (error instanceof ApiError) errors.value = error.validation;
                throw error;
            }
        }, load);
    } finally {
        saving.value = null;
    }
};

const firstError = (errors: Record<string, string[]>, field: string) => errors[field]?.[0];

const copy = async (value: string) => {
    try {
        await navigator.clipboard.writeText(value);
    } catch {
        // iframe 里剪贴板权限可能被拒，退回让商家手动复制。
        window.prompt('复制这个地址：', value);
    }
};
</script>

<template>
    <p v-if="loading" class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
        正在加载应用配置…
    </p>

    <div v-else-if="loadError" class="rounded-2xl border border-rose-200 bg-white p-6">
        <p class="text-sm text-slate-600">{{ loadError }}</p>
    </div>

    <div v-else-if="settings" class="space-y-5">
        <section class="rounded-2xl border border-slate-200 bg-white p-6">
            <h1 class="text-2xl font-semibold tracking-tight text-slate-950">应用配置</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-500">
                这里的凭证只属于本店铺，保存后立即生效，不影响其它店铺。
                需要两样东西：一个 Meta 应用（用来读你的 Instagram 内容）和一个 Cloudflare R2 存储桶
                （Instagram 的原始链接会过期，必须把文件转存到自己的永久地址）。
            </p>
            <p class="mt-2 text-xs text-slate-400">当前环境：{{ environment }}</p>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <header class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 border-b border-slate-100 p-6">
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold text-slate-950">Instagram / Facebook 应用凭证</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ sourceNote(settings.source.meta) }}</p>
                </div>
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    <span
                        class="inline-flex shrink-0 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold ring-1"
                        :class="instagramReady ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-amber-50 text-amber-700 ring-amber-200'"
                    >
                        Instagram 登录 {{ instagramReady ? '已就绪' : '未配置' }}
                    </span>
                    <span
                        class="inline-flex shrink-0 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold ring-1"
                        :class="facebookReady ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-amber-50 text-amber-700 ring-amber-200'"
                    >
                        Facebook 登录 {{ facebookReady ? '已就绪' : '未配置' }}
                    </span>
                </div>
            </header>

            <form class="p-6" @submit.prevent="submit('meta')">
                <div class="rounded-2xl bg-sky-50 p-4 ring-1 ring-sky-100">
                    <h3 class="text-sm font-semibold text-sky-950">两条授权链路，配一条就能用</h3>
                    <p class="mt-1 text-xs leading-5 text-sky-800">
                        Instagram 登录让你直接授权 Instagram 专业账号；Facebook 登录适用于通过 Facebook 主页绑定的账号。
                        哪条没配齐，对应的连接按钮就不出现，不影响另一条。
                    </p>
                    <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div class="min-w-0">
                            <dt class="text-xs font-semibold text-sky-950">Instagram 回调地址</dt>
                            <dd class="mt-1 flex items-center gap-2">
                                <code class="min-w-0 flex-1 truncate rounded bg-white px-2 py-1 font-mono text-xs text-sky-800" :title="settings.callbacks.instagram">
                                    {{ settings.callbacks.instagram }}
                                </code>
                                <button
                                    type="button"
                                    class="shrink-0 whitespace-nowrap rounded-lg border border-sky-300 bg-white px-2.5 py-1 text-xs font-semibold text-sky-800 hover:bg-sky-100"
                                    @click="copy(settings.callbacks.instagram)"
                                >
                                    复制
                                </button>
                            </dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-xs font-semibold text-sky-950">Facebook 回调地址</dt>
                            <dd class="mt-1 flex items-center gap-2">
                                <code class="min-w-0 flex-1 truncate rounded bg-white px-2 py-1 font-mono text-xs text-sky-800" :title="settings.callbacks.facebook">
                                    {{ settings.callbacks.facebook }}
                                </code>
                                <button
                                    type="button"
                                    class="shrink-0 whitespace-nowrap rounded-lg border border-sky-300 bg-white px-2.5 py-1 text-xs font-semibold text-sky-800 hover:bg-sky-100"
                                    @click="copy(settings.callbacks.facebook)"
                                >
                                    复制
                                </button>
                            </dd>
                        </div>
                    </dl>
                    <p class="mt-2 text-xs leading-5 text-sky-800">
                        回调地址要填进 Meta 后台的 OAuth 设置，必须完全一致，包括协议和结尾斜杠。
                    </p>
                </div>

                <div class="mt-6 grid gap-5 sm:grid-cols-2">
                    <label class="block min-w-0">
                        <span class="text-sm font-semibold text-slate-800">Instagram App ID</span>
                        <input
                            v-model="metaForm.instagram_app_id"
                            type="text"
                            autocomplete="off"
                            placeholder="Meta 应用 → Instagram → API 设置"
                            class="mt-2 w-full rounded-xl border border-slate-300 px-3.5 py-2.5 font-mono text-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
                        >
                        <span v-if="firstError(metaErrors, 'instagram_app_id')" class="mt-1 block text-xs text-rose-600">
                            {{ firstError(metaErrors, 'instagram_app_id') }}
                        </span>
                    </label>

                    <SecretField
                        v-model="metaForm.instagram_app_secret"
                        label="Instagram App Secret"
                        :configured="settings.meta.instagram_app_secret_configured"
                        :placeholder="secretPlaceholder(settings.meta.instagram_app_secret_configured)"
                        :error="firstError(metaErrors, 'instagram_app_secret')"
                    />

                    <label class="block min-w-0">
                        <span class="text-sm font-semibold text-slate-800">Facebook App ID</span>
                        <input
                            v-model="metaForm.facebook_app_id"
                            type="text"
                            autocomplete="off"
                            placeholder="Meta 应用 → 设置 → 基本"
                            class="mt-2 w-full rounded-xl border border-slate-300 px-3.5 py-2.5 font-mono text-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
                        >
                        <span v-if="firstError(metaErrors, 'facebook_app_id')" class="mt-1 block text-xs text-rose-600">
                            {{ firstError(metaErrors, 'facebook_app_id') }}
                        </span>
                    </label>

                    <SecretField
                        v-model="metaForm.facebook_app_secret"
                        label="Facebook App Secret"
                        :configured="settings.meta.facebook_app_secret_configured"
                        :placeholder="secretPlaceholder(settings.meta.facebook_app_secret_configured)"
                        :error="firstError(metaErrors, 'facebook_app_secret')"
                    />

                    <label class="block min-w-0 sm:col-span-2">
                        <span class="text-sm font-semibold text-slate-800">Facebook 配置化登录 ID（可选）</span>
                        <input
                            v-model="metaForm.facebook_login_config_id"
                            type="text"
                            autocomplete="off"
                            placeholder="填了之后授权不再单独传权限范围"
                            class="mt-2 w-full rounded-xl border border-slate-300 px-3.5 py-2.5 font-mono text-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
                        >
                        <span v-if="firstError(metaErrors, 'facebook_login_config_id')" class="mt-1 block text-xs text-rose-600">
                            {{ firstError(metaErrors, 'facebook_login_config_id') }}
                        </span>
                    </label>
                </div>

                <div class="mt-6 flex flex-wrap items-center justify-end gap-3 border-t border-slate-100 pt-5">
                    <button
                        type="submit"
                        class="inline-flex shrink-0 items-center gap-2 whitespace-nowrap rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                        :disabled="busy"
                    >
                        <span
                            v-if="saving === 'meta'"
                            class="size-3.5 shrink-0 animate-spin rounded-full border-2 border-white/40 border-t-white"
                            aria-hidden="true"
                        />
                        保存应用凭证
                    </button>
                </div>
            </form>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <header class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 border-b border-slate-100 p-6">
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold text-slate-950">Cloudflare R2 存储</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ sourceNote(settings.source.r2) }}</p>
                </div>
                <span
                    class="inline-flex shrink-0 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold ring-1"
                    :class="r2Ready ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-amber-50 text-amber-700 ring-amber-200'"
                >
                    存储 {{ r2Ready ? '已就绪' : '未配置' }}
                </span>
            </header>

            <form class="p-6" @submit.prevent="submit('r2')">
                <div class="rounded-2xl bg-amber-50 p-4 ring-1 ring-amber-100">
                    <p class="text-xs leading-5 text-amber-900">
                        五项要全部填齐才算就绪。公开访问地址是绑定在存储桶上的自定义域名，
                        必须是 HTTPS —— 前台展示的就是这个地址。换桶之后，已经转存过的内容仍指向旧桶，
                        需要重新转存才会搬到新桶。
                    </p>
                </div>

                <div class="mt-6 grid gap-5 sm:grid-cols-2">
                    <label class="block min-w-0">
                        <span class="text-sm font-semibold text-slate-800">账号 ID</span>
                        <input
                            v-model="r2Form.account_id"
                            type="text"
                            autocomplete="off"
                            placeholder="Cloudflare 仪表盘右侧的 Account ID"
                            class="mt-2 w-full rounded-xl border border-slate-300 px-3.5 py-2.5 font-mono text-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
                        >
                        <span v-if="firstError(r2Errors, 'account_id')" class="mt-1 block text-xs text-rose-600">
                            {{ firstError(r2Errors, 'account_id') }}
                        </span>
                    </label>

                    <label class="block min-w-0">
                        <span class="text-sm font-semibold text-slate-800">存储桶名称</span>
                        <input
                            v-model="r2Form.bucket"
                            type="text"
                            autocomplete="off"
                            placeholder="小写字母、数字、点和短横线"
                            class="mt-2 w-full rounded-xl border border-slate-300 px-3.5 py-2.5 font-mono text-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
                        >
                        <span v-if="firstError(r2Errors, 'bucket')" class="mt-1 block text-xs text-rose-600">
                            {{ firstError(r2Errors, 'bucket') }}
                        </span>
                    </label>

                    <label class="block min-w-0">
                        <span class="text-sm font-semibold text-slate-800">Access Key ID</span>
                        <input
                            v-model="r2Form.access_key_id"
                            type="text"
                            autocomplete="off"
                            placeholder="R2 API 令牌的 Access Key ID"
                            class="mt-2 w-full rounded-xl border border-slate-300 px-3.5 py-2.5 font-mono text-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
                        >
                        <span v-if="firstError(r2Errors, 'access_key_id')" class="mt-1 block text-xs text-rose-600">
                            {{ firstError(r2Errors, 'access_key_id') }}
                        </span>
                    </label>

                    <SecretField
                        v-model="r2Form.secret_access_key"
                        label="Secret Access Key"
                        :configured="settings.r2.secret_access_key_configured"
                        :placeholder="secretPlaceholder(settings.r2.secret_access_key_configured)"
                        :error="firstError(r2Errors, 'secret_access_key')"
                    />

                    <label class="block min-w-0 sm:col-span-2">
                        <span class="text-sm font-semibold text-slate-800">公开访问地址</span>
                        <input
                            v-model="r2Form.public_base_url"
                            type="url"
                            autocomplete="off"
                            placeholder="https://media.example.com"
                            class="mt-2 w-full rounded-xl border border-slate-300 px-3.5 py-2.5 font-mono text-sm focus:border-slate-900 focus:outline-none focus:ring-1 focus:ring-slate-900"
                        >
                        <span v-if="firstError(r2Errors, 'public_base_url')" class="mt-1 block text-xs text-rose-600">
                            {{ firstError(r2Errors, 'public_base_url') }}
                        </span>
                    </label>
                </div>

                <div class="mt-6 flex flex-wrap items-center justify-end gap-3 border-t border-slate-100 pt-5">
                    <button
                        type="submit"
                        class="inline-flex shrink-0 items-center gap-2 whitespace-nowrap rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                        :disabled="busy"
                    >
                        <span
                            v-if="saving === 'r2'"
                            class="size-3.5 shrink-0 animate-spin rounded-full border-2 border-white/40 border-t-white"
                            aria-hidden="true"
                        />
                        保存存储配置
                    </button>
                </div>
            </form>
        </section>
    </div>
</template>
