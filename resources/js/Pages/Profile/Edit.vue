<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AvatarUploader from '../../Components/Users/AvatarUploader.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { User } from '../../types';

const props = defineProps<{ profile: { data: User } }>();
const showPassword = ref(false);
const form = useForm({
    name: props.profile.data.name,
    email: props.profile.data.email,
    password: '',
});
const avatarForm = useForm({ avatar: null as File | null });
const removeAvatarForm = useForm({});
const avatarSaving = computed(() => avatarForm.processing || removeAvatarForm.processing);

const saveAvatar = (avatar: File | null) => {
    if (!avatar) return;

    avatarForm.avatar = avatar;
    avatarForm.clearErrors();
    avatarForm.post('/profile/avatar', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => avatarForm.reset(),
    });
};

const removeAvatar = (remove: boolean) => {
    if (!remove) return;

    removeAvatarForm.delete('/profile/avatar', { preserveScroll: true });
};

const submit = () => {
    form.put('/profile', {
        preserveScroll: true,
        onSuccess: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="我的资料" />
    <AppLayout :breadcrumbs="[{ label: '我的资料' }]">
        <div class="mx-auto max-w-4xl">
            <div>
                <p class="text-sm font-semibold text-emerald-700">账户设置</p>
                <h1 class="mt-1 text-2xl font-semibold text-slate-950">我的资料</h1>
                <p class="mt-1 text-sm text-slate-500">管理自己的头像、姓名、邮箱和登录密码。</p>
            </div>

            <form class="mt-6" @submit.prevent="submit">
                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-5 py-5 sm:px-7">
                        <h2 class="font-semibold text-slate-950">个人资料</h2>
                        <p class="mt-1 text-sm text-slate-500">头像单独自动保存，其他资料点击页面底部按钮保存。</p>
                    </div>

                    <div class="space-y-7 p-5 sm:p-7">
                        <AvatarUploader
                            :name="form.name"
                            :current-url="props.profile.data.avatar_url"
                            :error="avatarForm.errors.avatar"
                            :saving="avatarSaving"
                            immediate
                            @update:file="saveAvatar"
                            @update:remove="removeAvatar"
                        />

                        <div class="border-t border-slate-100 pt-7">
                            <h3 class="text-sm font-semibold text-slate-950">基本信息</h3>
                            <div class="mt-4 grid gap-5 sm:grid-cols-2">
                                <label class="text-sm font-medium text-slate-800">
                                    姓名
                                    <input v-model="form.name" required autocomplete="name" class="mt-2 w-full rounded-xl border border-slate-300 px-3.5 py-3 font-normal outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10" />
                                    <span v-if="form.errors.name" class="mt-1.5 block text-xs text-rose-600">{{ form.errors.name }}</span>
                                </label>

                                <label class="text-sm font-medium text-slate-800">
                                    邮箱
                                    <input v-model="form.email" required type="email" autocomplete="email" class="mt-2 w-full rounded-xl border border-slate-300 px-3.5 py-3 font-normal outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10" />
                                    <span v-if="form.errors.email" class="mt-1.5 block text-xs text-rose-600">{{ form.errors.email }}</span>
                                </label>

                                <label class="text-sm font-medium text-slate-800">
                                    新密码
                                    <span class="relative mt-2 block">
                                        <input v-model="form.password" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" class="w-full rounded-xl border border-slate-300 px-3.5 py-3 pr-12 font-normal outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-500/10" placeholder="留空则保持当前密码" />
                                        <button type="button" class="absolute inset-y-0 right-1.5 grid w-10 place-items-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" :aria-label="showPassword ? '隐藏密码' : '显示密码'" @click="showPassword = !showPassword">
                                            <svg v-if="!showPassword" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                                            <svg v-else class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 3 18 18"/><path d="M10.6 10.6A2 2 0 0 0 13.4 13.4"/><path d="M9.9 4.2A10.4 10.4 0 0 1 12 4c6.5 0 10 8 10 8a16 16 0 0 1-2.1 3.2M6.6 6.6C3.5 8.5 2 12 2 12s3.5 8 10 8a9.8 9.8 0 0 0 4.1-.9"/></svg>
                                        </button>
                                    </span>
                                    <span v-if="form.errors.password" class="mt-1.5 block text-xs text-rose-600">{{ form.errors.password }}</span>
                                </label>

                                <div>
                                    <p class="text-sm font-medium text-slate-800">状态</p>
                                    <div class="mt-2 flex min-h-12 items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-3">
                                        <span class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700"><span class="h-2 w-2 rounded-full" :class="props.profile.data.status === 'active' ? 'bg-emerald-500' : 'bg-slate-400'" />{{ props.profile.data.status === 'active' ? '启用' : '停用' }}</span>
                                        <span class="inline-flex items-center gap-1 text-xs text-slate-400"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>仅管理员可修改</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end border-t border-slate-100 bg-slate-50/70 px-5 py-4 sm:px-7">
                        <button :disabled="form.processing" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                            {{ form.processing ? '保存中…' : '保存用户' }}
                        </button>
                    </div>
                </section>
            </form>
        </div>
    </AppLayout>
</template>
