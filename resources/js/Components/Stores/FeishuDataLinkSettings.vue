<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { useToast } from '../../composables/useToast';

export interface FeishuDataLinkField {
    key: string;
    label: string;
    env_key: string;
    secret: boolean;
    placeholder: string;
    configured: boolean;
    masked_value: string;
    current_value: string;
}

export interface FeishuDataLinkSection {
    key: string;
    title: string;
    description: string;
    configured: boolean;
    fields: FeishuDataLinkField[];
}

const props = defineProps<{
    sections: FeishuDataLinkSection[];
    initialSection?: string;
    canUpdate: boolean;
}>();

const initialKey = props.sections.some((section) => section.key === props.initialSection)
    ? props.initialSection as string
    : props.sections[0]?.key ?? '';
const activeKey = ref(initialKey);
const values = reactive<Record<string, string>>({});
const revealed = reactive<Record<string, boolean>>({});
const revealLoading = reactive<Record<string, boolean>>({});
const saving = ref(false);
const dirty = ref(false);
const toast = useToast();

const activeSection = computed(() => props.sections.find((section) => section.key === activeKey.value) ?? props.sections[0]);

const hydrate = () => {
    props.sections.forEach((section) => {
        section.fields.forEach((field) => {
            if (!(field.key in values)) values[field.key] = field.current_value ?? '';
        });
    });
};

watch(() => props.sections, hydrate, { immediate: true, deep: true });

const selectSection = (key: string) => {
    activeKey.value = key;
    dirty.value = false;
};

const inputType = (field: FeishuDataLinkField) => field.secret && !revealed[field.key] ? 'password' : 'text';

const placeholder = (field: FeishuDataLinkField) => field.configured
    ? `当前：${field.masked_value || '已配置'}（留空不变，输入新值覆盖）`
    : field.placeholder;

const reveal = async (field: FeishuDataLinkField) => {
    if (values[field.key]) {
        revealed[field.key] = !revealed[field.key];
        return;
    }

    if (!field.configured || !props.canUpdate || revealLoading[field.key] || !activeSection.value) return;

    revealLoading[field.key] = true;
    try {
        const url = `/store-settings/feishu/data-links/${encodeURIComponent(activeSection.value.key)}/${encodeURIComponent(field.key)}/reveal`;
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error('Unable to reveal value');

        const payload = await response.json() as { data?: { value?: string } };
        values[field.key] = payload.data?.value ?? '';
        revealed[field.key] = true;
    } catch {
        toast.error(`无法查看 ${field.label}，请确认当前账号具有店铺编辑权限。`);
    } finally {
        revealLoading[field.key] = false;
    }
};

const markDirty = () => { dirty.value = true; };

const save = () => {
    const section = activeSection.value;
    if (!section || saving.value || !props.canUpdate || !dirty.value) return;

    saving.value = true;
    const payload = Object.fromEntries(section.fields.map((field) => [field.key, values[field.key] ?? '']));

    router.put(`/store-settings/feishu/data-links/${encodeURIComponent(section.key)}`, { values: payload }, {
        preserveScroll: true,
        onSuccess: () => {
            section.fields.forEach((field) => {
                if (field.secret) {
                    values[field.key] = '';
                    revealed[field.key] = false;
                }
            });
            dirty.value = false;
            toast.success(`${section.title}已保存到当前店铺。`);
        },
        onError: () => toast.error(`${section.title}保存失败，请检查输入内容。`),
        onFinish: () => { saving.value = false; },
    });
};
</script>

<template>
    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <header class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 px-5 py-5 sm:px-7">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-cyan-700">当前店铺独立配置</p>
                <h2 class="mt-1 text-xl font-semibold text-slate-950">飞书数据链接</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">为当前店铺配置各业务模块使用的飞书表格、视图和 Wiki 节点；输入后离开输入框会自动保存。</p>
            </div>
            <span class="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600">{{ sections.filter((section) => section.configured).length }} / {{ sections.length }} 已配置</span>
        </header>

        <div v-if="activeSection" class="grid min-h-[520px] md:grid-cols-[13rem_minmax(0,1fr)]">
            <nav class="border-b border-slate-100 bg-slate-50/70 p-3 md:border-b-0 md:border-r" aria-label="飞书数据类别">
                <div class="grid grid-cols-2 gap-1 sm:grid-cols-3 md:grid-cols-1">
                    <button
                        v-for="section in sections"
                        :key="section.key"
                        type="button"
                        class="flex min-w-0 items-center justify-between gap-2 rounded-xl px-3 py-3 text-left text-sm font-semibold transition"
                        :class="section.key === activeSection.key ? 'bg-slate-950 text-white shadow-sm' : 'text-slate-600 hover:bg-white hover:text-slate-950'"
                        @click="selectSection(section.key)"
                    >
                        <span class="truncate">{{ section.title }}</span>
                    </button>
                </div>
            </nav>

            <form class="min-w-0 p-5 sm:p-7" @submit.prevent="save">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 pb-5">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-lg font-semibold text-slate-950">{{ activeSection.title }}</h3>
                            <span class="rounded-md px-2 py-0.5 text-xs font-semibold" :class="activeSection.configured ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'">{{ activeSection.configured ? '已配置' : '未配置' }}</span>
                        </div>
                        <p class="mt-2 text-sm leading-6 text-slate-500">{{ activeSection.description }}</p>
                    </div>
                    <span v-if="dirty" class="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700">有未保存修改</span>
                </div>

                <div class="divide-y divide-slate-100">
                    <label v-for="field in activeSection.fields" :key="field.key" class="block py-5">
                        <span class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-sm font-semibold text-slate-800">{{ field.label }}</span>
                            <span class="flex items-center gap-2">
                                <code class="text-xs text-slate-400">{{ field.env_key }}</code>
                                <span class="rounded-md px-2 py-0.5 text-xs font-semibold" :class="field.configured ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'">{{ field.configured ? '已配置' : '未配置' }}</span>
                            </span>
                        </span>
                        <span class="mt-2 flex overflow-hidden rounded-xl border border-slate-200 bg-white transition focus-within:border-cyan-500 focus-within:ring-2 focus-within:ring-cyan-100" :class="{ 'bg-slate-50 opacity-70': !canUpdate }">
                            <input
                                v-model="values[field.key]"
                                :type="inputType(field)"
                                :placeholder="placeholder(field)"
                                :disabled="!canUpdate"
                                autocomplete="off"
                                class="min-w-0 flex-1 border-0 bg-transparent px-3.5 py-2.5 font-mono text-sm text-slate-900 outline-none placeholder:font-sans placeholder:text-slate-400"
                                @input="markDirty"
                                @change="save"
                            />
                            <button v-if="field.secret" type="button" :disabled="!canUpdate || revealLoading[field.key] || (!values[field.key] && !field.configured)" class="inline-flex w-11 shrink-0 items-center justify-center border-l border-slate-100 text-slate-500 transition hover:bg-slate-50 hover:text-slate-900 disabled:opacity-30" :aria-label="revealed[field.key] ? `隐藏 ${field.label}` : `显示 ${field.label}`" @click="reveal(field)">
                                <svg v-if="revealLoading[field.key]" class="h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" opacity=".25"/><path d="M21 12a9 9 0 0 0-9-9"/></svg>
                                <svg v-else-if="!revealed[field.key]" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                                <svg v-else class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 3 18 18"/><path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"/><path d="M9.9 4.2A10.7 10.7 0 0 1 12 4c6.5 0 10 8 10 8a18.6 18.6 0 0 1-2.2 3.2M6.6 6.6C3.5 8.5 2 12 2 12s3.5 8 10 8a10.7 10.7 0 0 0 4.1-.8"/></svg>
                            </button>
                        </span>
                    </label>
                </div>

                <div v-if="canUpdate" class="flex items-center justify-end border-t border-slate-100 pt-5">
                    <button :disabled="saving || !dirty" class="rounded-xl bg-slate-950 px-6 py-3 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-40">{{ saving ? '保存中…' : '保存当前分类' }}</button>
                </div>
            </form>
        </div>
    </section>
</template>
