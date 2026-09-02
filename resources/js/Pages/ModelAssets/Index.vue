<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AssetConfirmDialog from '../../Components/ModelAssets/AssetConfirmDialog.vue';
import Pagination from '../../Components/Navigation/Pagination.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

interface FolderRow {
    id: string;
    name: string;
    image_count: number;
    created_at: string | null;
}
interface Page<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

const props = defineProps<{
    store: { id: number; name: string };
    folders: Page<FolderRow>;
    permissions: { manage: boolean };
}>();
const form = useForm({ name: '' });
const folderToDelete = ref<FolderRow | null>(null);
const deletingFolder = ref(false);

const createFolder = () => {
    form.post('/model-assets/folders', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};
const deleteFolder = (folder: FolderRow) => {
    folderToDelete.value = folder;
};
const confirmFolderDelete = () => {
    if (!folderToDelete.value || deletingFolder.value) return;
    deletingFolder.value = true;
    router.delete(`/model-assets/folders/${folderToDelete.value.id}`, {
        preserveScroll: true,
        onSuccess: () => { folderToDelete.value = null; },
        onFinish: () => { deletingFolder.value = false; },
    });
};
</script>

<template>
    <Head title="车型素材" />
    <AppLayout :breadcrumbs="[{ label: '工作台', href: '/dashboard' }, { label: '业务中心' }, { label: '车型素材' }]">
        <div class="mx-auto max-w-[1600px] space-y-6">
            <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold text-emerald-700">{{ store.name }}</p>
                    <h1 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">车型素材</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-500">用文件夹整理当前店铺的车型图片素材。</p>
                </div>
                <form v-if="permissions.manage" class="flex w-full max-w-xl gap-2" @submit.prevent="createFolder">
                    <label class="min-w-0 flex-1">
                        <span class="sr-only">文件夹名称</span>
                        <input v-model="form.name" maxlength="80" required class="h-11 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none transition focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100" placeholder="输入新文件夹名称">
                        <span v-if="form.errors.name" class="mt-1.5 block text-xs font-medium text-rose-600">{{ form.errors.name }}</span>
                    </label>
                    <button :disabled="form.processing" class="h-11 shrink-0 rounded-xl bg-slate-950 px-5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                        {{ form.processing ? '创建中…' : '新建文件夹' }}
                    </button>
                </form>
            </header>

            <section v-if="folders.data.length" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                <article v-for="folder in folders.data" :key="folder.id" class="group relative overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-200 hover:shadow-lg hover:shadow-slate-900/5">
                    <Link :href="`/model-assets/folders/${folder.id}`" class="block p-6">
                        <div class="flex items-start justify-between gap-4">
                            <span class="grid h-14 w-16 place-items-center rounded-2xl bg-gradient-to-br from-sky-100 to-blue-50 text-sky-600 shadow-inner">
                                <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 7.5A2.5 2.5 0 0 1 5.5 5h4l2 2h7A2.5 2.5 0 0 1 21 9.5v8A2.5 2.5 0 0 1 18.5 20h-13A2.5 2.5 0 0 1 3 17.5z"/><path d="M3 10h18"/></svg>
                            </span>
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-500">{{ folder.image_count }} 张</span>
                        </div>
                        <h2 class="mt-5 truncate text-lg font-semibold text-slate-900">{{ folder.name }}</h2>
                        <p class="mt-1 text-xs text-slate-400">点击打开文件夹</p>
                    </Link>
                    <button v-if="permissions.manage" type="button" class="absolute right-3 bottom-3 grid h-9 w-9 place-items-center rounded-xl text-slate-300 opacity-100 transition hover:bg-rose-50 hover:text-rose-600 sm:opacity-0 sm:group-hover:opacity-100 sm:focus:opacity-100" :aria-label="`删除文件夹 ${folder.name}`" @click="deleteFolder(folder)">
                        <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M9 7V4h6v3m-8 0 1 13h8l1-13M10 11v5m4-5v5"/></svg>
                    </button>
                </article>
            </section>

            <section v-else class="grid min-h-80 place-items-center rounded-3xl border border-dashed border-slate-300 bg-white p-8 text-center">
                <div>
                    <span class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-sky-50 text-sky-500"><svg class="h-9 w-9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 7.5A2.5 2.5 0 0 1 5.5 5h4l2 2h7A2.5 2.5 0 0 1 21 9.5v8A2.5 2.5 0 0 1 18.5 20h-13A2.5 2.5 0 0 1 3 17.5z"/><path d="M3 10h18"/></svg></span>
                    <h2 class="mt-4 text-lg font-semibold text-slate-900">还没有素材文件夹</h2>
                    <p class="mt-2 text-sm text-slate-500">新建一个文件夹后即可上传车型图片。</p>
                </div>
            </section>

            <Pagination :links="folders.links" />
        </div>

        <AssetConfirmDialog
            :open="folderToDelete !== null"
            title="永久删除文件夹？"
            :message="folderToDelete ? `文件夹“${folderToDelete.name}”及其中 ${folderToDelete.image_count} 张图片都会被彻底删除，此操作无法恢复。` : ''"
            :processing="deletingFolder"
            @cancel="folderToDelete = null"
            @confirm="confirmFolderDelete"
        />
    </AppLayout>
</template>
