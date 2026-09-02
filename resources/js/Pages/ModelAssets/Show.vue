<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AssetConfirmDialog from '../../Components/ModelAssets/AssetConfirmDialog.vue';
import Pagination from '../../Components/Navigation/Pagination.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

interface AssetImage {
    id: string;
    url: string;
    thumbnail_url: string;
    width: number;
    height: number;
    byte_size: number;
    created_at: string;
}
interface Page<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
}
interface PendingUpload {
    id: string;
    file: File;
    preview: string;
    progress: number;
    status: 'queued' | 'uploading' | 'failed';
    error: string;
    request?: XMLHttpRequest;
}

const props = defineProps<{
    store: { id: number; name: string };
    folder: { id: string; name: string; image_count: number };
    images: Page<AssetImage>;
    permissions: { manage: boolean };
}>();

const fileInput = ref<HTMLInputElement | null>(null);
const images = ref<AssetImage[]>([...props.images.data]);
const uploads = ref<PendingUpload[]>([]);
const pageError = ref('');
const totalCount = ref(props.folder.image_count);
const selectedImage = ref<AssetImage | null>(null);
const deleteIntent = ref<{ type: 'image'; image: AssetImage } | { type: 'clear' } | null>(null);
const deleting = ref(false);
let activeUploads = 0;
let disposed = false;
const maximumConcurrentUploads = 3;

const hasActiveUploads = computed(() => uploads.value.some(item => item.status === 'queued' || item.status === 'uploading'));
const csrfToken = () => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
};
const requestHeaders = () => {
    const token = csrfToken();
    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(token ? { 'X-XSRF-TOKEN': token } : {}),
    };
};
const errorMessage = (body: unknown, fallback: string) => {
    if (!body || typeof body !== 'object') return fallback;
    const payload = body as { message?: string; errors?: Record<string, string[]> };
    const validation = payload.errors ? Object.values(payload.errors).flat()[0] : '';
    return validation || payload.message || fallback;
};

const validateFiles = (files: File[]) => {
    const allowed = new Set(['image/jpeg', 'image/png', 'image/webp']);
    const accepted: File[] = [];
    const errors: string[] = [];
    for (const file of files) {
        if (!allowed.has(file.type)) {
            errors.push(`${file.name || '文件'}不是支持的图片格式`);
            continue;
        }
        if (file.size > 20 * 1024 * 1024) {
            errors.push(`${file.name || '图片'}超过 20MB`);
            continue;
        }
        accepted.push(file);
    }
    pageError.value = errors.length ? `${errors.slice(0, 3).join('；')}${errors.length > 3 ? '；还有其他文件未上传' : ''}` : '';
    return accepted;
};
const enqueueFiles = (files: File[]) => {
    if (!props.permissions.manage) return;
    for (const file of validateFiles(files)) {
        uploads.value.push({
            id: crypto.randomUUID(),
            file,
            preview: URL.createObjectURL(file),
            progress: 0,
            status: 'queued',
            error: '',
        });
    }
    pumpQueue();
};
const onFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    enqueueFiles(Array.from(input.files ?? []));
    input.value = '';
};
const chooseFiles = () => fileInput.value?.click();

const finishUpload = (item: PendingUpload) => {
    activeUploads = Math.max(0, activeUploads - 1);
    item.request = undefined;
    pumpQueue();
};
const uploadOne = (item: PendingUpload) => {
    item.status = 'uploading';
    item.progress = 0;
    item.error = '';
    activeUploads++;

    const request = new XMLHttpRequest();
    item.request = request;
    request.open('POST', `/model-assets/folders/${props.folder.id}/images`);
    request.responseType = 'json';
    request.withCredentials = true;
    for (const [name, value] of Object.entries(requestHeaders())) request.setRequestHeader(name, value);
    request.upload.onprogress = event => {
        if (event.lengthComputable) item.progress = Math.min(99, Math.round(event.loaded / event.total * 100));
    };
    request.onload = () => {
        if (request.status >= 200 && request.status < 300) {
            const image = request.response?.data as AssetImage | undefined;
            if (image) {
                item.progress = 100;
                images.value.unshift(image);
                totalCount.value++;
                window.setTimeout(() => {
                    URL.revokeObjectURL(item.preview);
                    uploads.value = uploads.value.filter(upload => upload.id !== item.id);
                }, 220);
            } else {
                item.status = 'failed';
                item.error = '服务器未返回图片信息。';
            }
        } else {
            item.status = 'failed';
            item.error = errorMessage(request.response, '上传失败，请重试。');
        }
        finishUpload(item);
    };
    request.onerror = () => {
        item.status = 'failed';
        item.error = '网络异常，上传失败。';
        finishUpload(item);
    };
    request.onabort = () => finishUpload(item);

    const data = new FormData();
    data.append('image', item.file, item.file.name || 'clipboard-image.png');
    request.send(data);
};
function pumpQueue() {
    if (disposed) return;
    while (activeUploads < maximumConcurrentUploads) {
        const next = uploads.value.find(item => item.status === 'queued');
        if (!next) break;
        uploadOne(next);
    }
}
const retryUpload = (item: PendingUpload) => {
    item.status = 'queued';
    item.progress = 0;
    item.error = '';
    pumpQueue();
};
const removePending = (item: PendingUpload) => {
    if (item.status === 'uploading') return;
    URL.revokeObjectURL(item.preview);
    uploads.value = uploads.value.filter(upload => upload.id !== item.id);
};

const onPaste = (event: ClipboardEvent) => {
    if (!props.permissions.manage) return;
    let files = Array.from(event.clipboardData?.files ?? []).filter(file => file.type.startsWith('image/'));
    if (!files.length) {
        files = Array.from(event.clipboardData?.items ?? [])
            .filter(item => item.kind === 'file' && item.type.startsWith('image/'))
            .map(item => item.getAsFile())
            .filter((file): file is File => file !== null);
    }
    if (!files.length) return;
    event.preventDefault();
    enqueueFiles(files);
};

const jsonDelete = async (url: string) => {
    const response = await fetch(url, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: requestHeaders(),
    });
    const body = await response.json().catch(() => null);
    if (!response.ok) throw new Error(errorMessage(body, '删除失败，请稍后重试。'));
    return body;
};
const requestDeleteImage = (image: AssetImage) => {
    deleteIntent.value = { type: 'image', image };
};
const requestClearFolder = () => {
    if (hasActiveUploads.value || totalCount.value === 0) return;
    deleteIntent.value = { type: 'clear' };
};
const confirmDelete = async () => {
    if (!deleteIntent.value || deleting.value) return;
    pageError.value = '';
    deleting.value = true;
    try {
        if (deleteIntent.value.type === 'image') {
            const image = deleteIntent.value.image;
            await jsonDelete(`/model-assets/images/${image.id}`);
            images.value = images.value.filter(item => item.id !== image.id);
            totalCount.value = Math.max(0, totalCount.value - 1);
            if (selectedImage.value?.id === image.id) selectedImage.value = null;
        } else {
            await jsonDelete(`/model-assets/folders/${props.folder.id}/images`);
            images.value = [];
            totalCount.value = 0;
            selectedImage.value = null;
        }
        deleteIntent.value = null;
    } catch (error) {
        pageError.value = error instanceof Error ? error.message : '删除失败，请稍后重试。';
    } finally {
        deleting.value = false;
    }
};
const confirmTitle = computed(() => deleteIntent.value?.type === 'clear' ? '永久清空文件夹？' : '永久删除这张图片？');
const confirmMessage = computed(() => deleteIntent.value?.type === 'clear'
    ? `“${props.folder.name}”中的全部 ${totalCount.value} 张图片都会被彻底删除，此操作无法恢复。`
    : '原图和缩略图都会被彻底删除，此操作无法恢复。');
const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape' && selectedImage.value && !deleteIntent.value) selectedImage.value = null;
};

onMounted(() => {
    window.addEventListener('paste', onPaste);
    window.addEventListener('keydown', onKeydown);
});
onBeforeUnmount(() => {
    disposed = true;
    window.removeEventListener('paste', onPaste);
    window.removeEventListener('keydown', onKeydown);
    for (const upload of uploads.value) {
        upload.request?.abort();
        URL.revokeObjectURL(upload.preview);
    }
});
</script>

<template>
    <Head :title="folder.name" />
    <AppLayout :breadcrumbs="[{ label: '工作台', href: '/dashboard' }, { label: '业务中心' }, { label: '车型素材', href: '/model-assets' }, { label: folder.name }]">
        <div class="mx-auto max-w-[1700px] space-y-6">
            <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <Link href="/model-assets" class="text-sm font-semibold text-emerald-700">← 返回素材文件夹</Link>
                    <h1 class="mt-3 text-3xl font-semibold tracking-tight text-slate-950">{{ folder.name }}</h1>
                    <p class="mt-2 text-sm text-slate-500">{{ totalCount }} 张图片 · 只显示图片缩略图</p>
                </div>
                <div v-if="permissions.manage" class="flex flex-wrap gap-2">
                    <button type="button" class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800" @click="chooseFiles">
                        上传多张图片
                    </button>
                    <button type="button" :disabled="totalCount === 0 || hasActiveUploads" class="rounded-xl border border-rose-200 bg-white px-5 py-2.5 text-sm font-semibold text-rose-600 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-40" @click="requestClearFolder">
                        一键清空
                    </button>
                    <input ref="fileInput" type="file" multiple accept="image/jpeg,image/png,image/webp" class="sr-only" @change="onFileChange">
                </div>
            </header>

            <div v-if="permissions.manage" class="flex items-center gap-3 rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-700">
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v12m0-12 4 4m-4-4L8 7"/><path d="M5 14v5h14v-5"/></svg>
                可一次选择多张图片，也可以在电脑中复制多张图片后，回到此页面按 Command/Ctrl + V 直接粘贴上传。
            </div>
            <p v-if="pageError" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700">{{ pageError }}</p>

            <section v-if="uploads.length || images.length" class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-7">
                <article v-for="upload in uploads" :key="upload.id" class="relative aspect-square overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <img :src="upload.preview" alt="" class="h-full w-full object-contain p-1" :class="upload.status === 'failed' ? 'opacity-40' : 'opacity-65'">
                    <div v-if="upload.status !== 'failed'" class="absolute inset-0 grid place-items-center bg-slate-950/25">
                        <div class="relative grid h-20 w-20 place-items-center rounded-full bg-slate-950/55 text-white shadow-xl backdrop-blur-sm">
                            <svg class="absolute inset-1 h-[72px] w-[72px] -rotate-90" viewBox="0 0 36 36">
                                <circle cx="18" cy="18" r="15.5" fill="none" stroke="rgba(255,255,255,.22)" stroke-width="2.5" />
                                <circle cx="18" cy="18" r="15.5" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" pathLength="100" :stroke-dasharray="100" :stroke-dashoffset="100-upload.progress" />
                            </svg>
                            <span class="text-sm font-semibold">{{ upload.progress }}%</span>
                        </div>
                    </div>
                    <div v-else class="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-slate-950/55 p-3 text-center text-white">
                        <p class="line-clamp-2 text-xs font-medium">{{ upload.error }}</p>
                        <div class="flex gap-2"><button class="rounded-lg bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-900" @click="retryUpload(upload)">重试</button><button class="rounded-lg border border-white/40 px-2.5 py-1.5 text-xs font-semibold" @click="removePending(upload)">移除</button></div>
                    </div>
                </article>

                <article v-for="image in images" :key="image.id" class="group relative aspect-square overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <button type="button" class="h-full w-full cursor-zoom-in" aria-label="查看原图" @click="selectedImage = image">
                        <img :src="image.thumbnail_url" alt="" loading="lazy" decoding="async" class="h-full w-full object-contain p-1 transition duration-300 group-hover:scale-[1.02]">
                    </button>
                    <button v-if="permissions.manage" type="button" class="absolute top-2 right-2 grid h-9 w-9 place-items-center rounded-full border border-white/50 bg-slate-950/80 text-xl leading-none text-white shadow-lg transition hover:scale-105 hover:bg-rose-600" aria-label="永久删除图片" @click.stop="requestDeleteImage(image)">×</button>
                </article>
            </section>

            <section v-else class="grid min-h-[420px] place-items-center rounded-3xl border border-dashed border-slate-300 bg-white p-8 text-center">
                <div>
                    <span class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-slate-100 text-slate-400"><svg class="h-9 w-9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9" r="1.5"/><path d="m4 17 5-5 4 4 2-2 5 4"/></svg></span>
                    <h2 class="mt-4 text-lg font-semibold text-slate-900">文件夹里还没有图片</h2>
                    <p class="mt-2 text-sm text-slate-500">{{ permissions.manage ? '选择多张图片，或直接复制粘贴到此页面。' : '当前文件夹暂无素材。' }}</p>
                    <button v-if="permissions.manage" type="button" class="mt-5 rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white" @click="chooseFiles">选择图片</button>
                </div>
            </section>

            <Pagination :links="props.images.links" />
        </div>

        <AssetConfirmDialog
            :open="deleteIntent !== null"
            :title="confirmTitle"
            :message="confirmMessage"
            :processing="deleting"
            @cancel="deleteIntent = null"
            @confirm="confirmDelete"
        />

        <Teleport to="body">
            <Transition enter-active-class="transition duration-150 ease-out" enter-from-class="opacity-0" leave-active-class="transition duration-100 ease-in" leave-to-class="opacity-0">
                <div v-if="selectedImage" class="fixed inset-0 z-[110] flex items-center justify-center bg-slate-950/90 p-4 backdrop-blur-sm sm:p-8" role="dialog" aria-modal="true" aria-label="查看原图" @mousedown.self="selectedImage = null">
                    <img :src="selectedImage.url" alt="" class="max-h-[92vh] max-w-full select-none object-contain shadow-2xl" @mousedown.stop>
                    <button type="button" class="absolute top-4 right-4 grid h-12 w-12 place-items-center rounded-full border border-white/20 bg-black/40 text-3xl leading-none text-white shadow-lg transition hover:bg-white hover:text-slate-950 sm:top-6 sm:right-6" aria-label="关闭原图" @click="selectedImage = null">×</button>
                </div>
            </Transition>
        </Teleport>
    </AppLayout>
</template>
