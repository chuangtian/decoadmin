<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import UserAvatar from './UserAvatar.vue';

const props = defineProps<{
    name: string;
    currentUrl?: string | null;
    error?: string;
    saving?: boolean;
    immediate?: boolean;
}>();
const emit = defineEmits<{
    'update:file': [file: File | null];
    'update:remove': [remove: boolean];
}>();

const input = ref<HTMLInputElement | null>(null);
const previewUrl = ref<string | null>(props.currentUrl ?? null);
const localError = ref('');
let objectUrl: string | null = null;

const revokeObjectUrl = () => {
    if (objectUrl) URL.revokeObjectURL(objectUrl);
    objectUrl = null;
};

const chooseAvatar = () => input.value?.click();
const onFileChange = (event: Event) => {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0] ?? null;

    if (!file) return;

    const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!allowedTypes.includes(file.type)) {
        localError.value = '请选择 JPG、PNG 或 WebP 图片。';
        target.value = '';
        return;
    }
    if (file.size > 2 * 1024 * 1024) {
        localError.value = '头像大小不能超过 2MB。';
        target.value = '';
        return;
    }

    revokeObjectUrl();
    objectUrl = URL.createObjectURL(file);
    previewUrl.value = objectUrl;
    localError.value = '';
    emit('update:file', file);
    emit('update:remove', false);
};
const removeAvatar = () => {
    revokeObjectUrl();
    previewUrl.value = null;
    localError.value = '';
    if (input.value) input.value.value = '';
    emit('update:file', null);
    emit('update:remove', true);
};

watch(() => props.currentUrl, (url) => {
    revokeObjectUrl();
    previewUrl.value = url ?? null;
});
watch(() => props.error, (error) => {
    if (!error) return;

    revokeObjectUrl();
    previewUrl.value = props.currentUrl ?? null;
});
onBeforeUnmount(revokeObjectUrl);
</script>

<template>
    <div class="rounded-2xl border border-slate-200 bg-gradient-to-br from-slate-50 via-white to-emerald-50/40 p-5 sm:p-6">
        <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
            <div class="relative w-fit">
                <UserAvatar :name="name || '新用户'" :url="previewUrl" size="xl" tone="emerald" class="shadow-sm" />
                <span class="absolute -right-1 -bottom-1 grid h-8 w-8 place-items-center rounded-full border-4 border-white bg-emerald-500 text-white shadow-sm">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg>
                </span>
            </div>

            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-slate-900">用户头像</p>
                <p class="mt-1 max-w-lg text-sm leading-6 text-slate-500">建议使用清晰的正方形照片。系统会居中裁切显示，支持 JPG、PNG、WebP，最大 2MB。</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <button type="button" :disabled="saving" class="inline-flex items-center gap-2 rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60" @click="chooseAvatar">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 16V4M7 9l5-5 5 5"/><path d="M20 15v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-4"/></svg>
                        {{ saving ? '正在保存…' : (previewUrl ? '更换头像' : '上传头像') }}
                    </button>
                    <button v-if="previewUrl" type="button" :disabled="saving" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-600 transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-600 disabled:cursor-wait disabled:opacity-60" @click="removeAvatar">移除头像</button>
                </div>
                <p v-if="immediate" class="mt-2 text-xs font-medium text-emerald-700">选择图片后将自动保存，无需再提交整张表单。</p>
                <input ref="input" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="onFileChange" />
                <p v-if="localError || error" class="mt-3 text-sm font-medium text-rose-600">{{ localError || error }}</p>
            </div>
        </div>
    </div>
</template>
