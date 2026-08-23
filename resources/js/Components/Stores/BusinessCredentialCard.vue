<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { nextTick, reactive, ref, watch } from 'vue';
import { useToast } from '../../composables/useToast';

type CredentialField = {
    key: string;
    label: string;
    env_key: string;
    secret: boolean;
    placeholder: string;
    configured: boolean;
    masked_value: string;
    current_value: string;
};

type CredentialProvider = {
    key: string;
    title: string;
    description: string;
    setup_hint: string;
    oauth_label: string | null;
    oauth_connect_title: string;
    oauth_connect_url: string | null;
    oauth_ready: boolean;
    oauth_connected: boolean;
    configured: boolean;
    fields: CredentialField[];
};

const props = defineProps<{
    provider: CredentialProvider;
    canUpdate: boolean;
    embedded?: boolean;
}>();

const values = reactive<Record<string, string>>({});
const revealed = reactive<Record<string, boolean>>({});
const revealLoading = reactive<Record<string, boolean>>({});
const processing = reactive<Record<string, boolean>>({});
const errors = reactive<Record<string, string>>({});
const clearTarget = ref<CredentialField | null>(null);
const clearProcessing = ref(false);
const clearCancelButton = ref<HTMLButtonElement | null>(null);
const toast = useToast();

const hydrateValues = () => {
    props.provider.fields.forEach((field) => {
        if (!(field.key in values) || (!field.secret && values[field.key] === '')) {
            values[field.key] = field.current_value ?? '';
        }
    });
};

watch(() => props.provider.fields, hydrateValues, { immediate: true, deep: true });

const endpoint = (field: CredentialField) => `/store-settings/credentials/${encodeURIComponent(props.provider.key)}/${encodeURIComponent(field.key)}`;

const inputType = (field: CredentialField) => field.secret && !revealed[field.key] ? 'password' : 'text';

const placeholder = (field: CredentialField) => field.configured
    ? `当前：${field.masked_value}（留空不变，输入新值覆盖）`
    : field.placeholder;

const reveal = async (field: CredentialField) => {
    if (values[field.key]) {
        revealed[field.key] = !revealed[field.key];
        return;
    }

    if (!field.configured || !props.canUpdate || revealLoading[field.key]) return;

    revealLoading[field.key] = true;
    try {
        const response = await fetch(`${endpoint(field)}/reveal`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error('Credential reveal failed');

        const payload = await response.json() as { data?: { value?: string } };
        values[field.key] = payload.data?.value ?? '';
        revealed[field.key] = true;
    } catch {
        toast.error(`无法查看 ${field.label}，请确认当前账号具有店铺编辑权限。`);
    } finally {
        revealLoading[field.key] = false;
    }
};

const save = (field: CredentialField) => {
    processing[field.key] = true;
    errors[field.key] = '';
    const savedValue = values[field.key] ?? '';

    router.put(endpoint(field), { value: savedValue }, {
        preserveScroll: true,
        onSuccess: () => {
            if (field.secret) {
                values[field.key] = '';
                revealed[field.key] = false;
            }
        },
        onError: (validationErrors) => {
            errors[field.key] = validationErrors.value ?? '保存失败，请检查输入内容。';
        },
        onFinish: () => { processing[field.key] = false; },
    });
};

const openClearDialog = async (field: CredentialField) => {
    if (!field.configured) return;
    clearTarget.value = field;
    await nextTick();
    clearCancelButton.value?.focus();
};

const closeClearDialog = () => {
    if (!clearProcessing.value) clearTarget.value = null;
};

const clear = () => {
    const field = clearTarget.value;
    if (!field) return;

    clearProcessing.value = true;
    router.delete(endpoint(field), {
        data: { confirmed: true },
        preserveScroll: true,
        onSuccess: () => {
            values[field.key] = '';
            revealed[field.key] = false;
            errors[field.key] = '';
            clearTarget.value = null;
        },
        onFinish: () => { clearProcessing.value = false; },
    });
};
</script>

<template>
    <section :class="embedded ? 'min-w-0 bg-white' : 'overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm'">
        <header class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 px-5 py-5 sm:px-7">
            <div class="min-w-0">
                <h2 class="text-xl font-semibold text-slate-950">{{ provider.title }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500">{{ provider.description }}</p>
                <p class="mt-1 text-sm text-slate-500"><span aria-hidden="true">📄</span> {{ provider.setup_hint }}</p>
            </div>
            <span class="rounded-full px-3 py-1.5 text-xs font-semibold" :class="provider.configured ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">
                {{ provider.configured ? '已配置' : '未配置' }}
            </span>
        </header>

        <div v-if="provider.oauth_label" class="mx-5 mt-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 sm:mx-7">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm font-semibold text-slate-800">{{ provider.oauth_label }}</span>
                <span class="rounded-md bg-blue-50 px-2 py-1 text-xs font-semibold text-blue-700">自动刷新</span>
            </div>
            <button v-if="provider.oauth_connected" type="button" disabled class="inline-flex cursor-default items-center gap-1.5 rounded-lg bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                已连接
            </button>
            <a
                v-else-if="provider.oauth_connect_url && provider.oauth_ready && canUpdate"
                :href="provider.oauth_connect_url"
                class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-200"
            >
                连接 {{ provider.oauth_connect_title }}
            </a>
            <span v-else-if="provider.oauth_connect_url && !provider.oauth_ready" class="rounded-md bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-700">请先配置 OAuth 凭证</span>
            <span v-else-if="provider.oauth_connect_url" class="rounded-md bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-500">仅店铺编辑者可连接</span>
            <span v-else class="rounded-md bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-700">授权流程待接入</span>
        </div>

        <div class="divide-y divide-slate-100 px-5 sm:px-7" :class="provider.oauth_label ? 'mt-3' : ''">
            <form v-for="field in provider.fields" :key="field.key" class="py-5" @submit.prevent="save(field)">
                <label :for="`${provider.key}-${field.key}`" class="flex flex-wrap items-center justify-between gap-2 text-sm font-semibold text-slate-800">
                    <span>{{ field.label }}</span>
                    <span class="flex items-center gap-2">
                        <code class="text-xs font-medium text-slate-400">{{ field.env_key }}</code>
                        <span class="rounded-md px-2 py-0.5 text-[11px] font-semibold" :class="field.configured ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'">
                            {{ field.configured ? '已配置' : '未配置' }}
                        </span>
                    </span>
                </label>

                <div class="mt-2 grid gap-3 lg:grid-cols-[minmax(0,1fr)_auto_auto]">
                    <div class="flex min-w-0 overflow-hidden rounded-xl border border-slate-200 bg-white transition focus-within:border-blue-500 focus-within:ring-2 focus-within:ring-blue-100" :class="{ 'bg-slate-50 opacity-70': !canUpdate }">
                        <input
                            :id="`${provider.key}-${field.key}`"
                            v-model="values[field.key]"
                            :type="inputType(field)"
                            :placeholder="placeholder(field)"
                            :disabled="!canUpdate"
                            autocomplete="off"
                            class="min-w-0 flex-1 border-0 bg-transparent px-3.5 py-2.5 font-mono text-sm text-slate-900 outline-none placeholder:font-sans placeholder:text-slate-400 disabled:cursor-not-allowed"
                        />
                        <button
                            v-if="field.secret"
                            type="button"
                            :disabled="!canUpdate || revealLoading[field.key] || (!values[field.key] && !field.configured)"
                            class="inline-flex w-11 shrink-0 items-center justify-center border-l border-slate-100 text-slate-500 transition hover:bg-slate-50 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-30"
                            :aria-label="revealed[field.key] ? `隐藏 ${field.label}` : `显示 ${field.label}`"
                            @click="reveal(field)"
                        >
                            <svg v-if="revealLoading[field.key]" class="h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" opacity=".25"/><path d="M21 12a9 9 0 0 0-9-9"/></svg>
                            <svg v-else-if="!revealed[field.key]" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg v-else class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 3 18 18"/><path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 4.2A10.7 10.7 0 0 1 12 4c6.5 0 10 8 10 8a18.6 18.6 0 0 1-2.2 3.2M6.6 6.6C3.5 8.5 2 12 2 12s3.5 8 10 8a10.7 10.7 0 0 0 4.1-.8"/></svg>
                        </button>
                    </div>
                    <button v-if="canUpdate" :disabled="processing[field.key] || clearProcessing" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50">
                        {{ processing[field.key] ? '保存中…' : '保存' }}
                    </button>
                    <button v-if="canUpdate" type="button" :disabled="!field.configured || processing[field.key] || clearProcessing" class="rounded-xl border border-rose-200 px-5 py-2.5 text-sm font-semibold text-rose-600 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-50 disabled:text-slate-400" @click="openClearDialog(field)">清除</button>
                </div>
                <p v-if="errors[field.key]" class="mt-1.5 text-xs text-rose-600">{{ errors[field.key] }}</p>
                <p v-else class="mt-1.5 text-xs leading-5 text-slate-400">凭证会加密保存在当前店铺；留空保存不会改变已有值。</p>
            </form>
        </div>

        <Teleport to="body">
            <div v-if="clearTarget" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm" @click.self="closeClearDialog" @keydown.esc="closeClearDialog">
                <section class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl" role="dialog" aria-modal="true" :aria-labelledby="`${provider.key}-clear-title`">
                    <h2 :id="`${provider.key}-clear-title`" class="text-lg font-semibold text-slate-950">清除 {{ clearTarget.label }}？</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        <template v-if="['meta_ads', 'tiktok_ads', 'google_ads', 'bing_ads', 'criteo'].includes(provider.key)">
                            清除后将删除当前店铺的这项凭证、该渠道全部广告数据和同步记录，并取消当前队列任务；必要凭证不完整时，此店铺也不会再参加每小时自动同步。此操作无法撤销。
                        </template>
                        <template v-else>
                            清除后，当前店铺将无法使用这项 {{ provider.title }} 凭证。此操作无法撤销。
                        </template>
                    </p>
                    <div class="mt-6 flex justify-end gap-3">
                        <button ref="clearCancelButton" type="button" :disabled="clearProcessing" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="closeClearDialog">取消</button>
                        <button type="button" :disabled="clearProcessing" class="rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-rose-700 disabled:opacity-60" @click="clear">{{ clearProcessing ? '清除中…' : '确认清除' }}</button>
                    </div>
                </section>
            </div>
        </Teleport>
    </section>
</template>
