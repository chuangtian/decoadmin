<script setup lang="ts">
/**
 * 帖子预览弹窗：嵌 Instagram 官方 embed。
 *
 * 为什么用 embed 而不是自己播视频：Instagram 出于版权保护，对部分 Reels（用了平台
 * 授权音乐、或关掉了「允许下载」）直接不返回视频文件地址，所以我们只转存封面图，
 * 播放交给 Instagram 自己。embed 不需要令牌，也不需要 oEmbed 权限。
 *
 * iframe 是跨域的，读不到内部高度，所以用固定的宽高比容器 + 允许内部滚动。
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Media } from './types';

const props = defineProps<{ media: Media | null }>();
const emit = defineEmits<{ (event: 'close'): void }>();

const loaded = ref(false);
const dialog = ref<HTMLElement | null>(null);

const open = computed(() => props.media !== null);
const embedUrl = computed(() => props.media?.embed_url ?? null);

const typeLabel = computed(() => {
    const media = props.media;
    if (! media) return '';
    if (media.media_type === 'VIDEO') return media.media_product_type === 'REELS' ? 'Reels' : '视频';

    return media.media_type === 'CAROUSEL_ALBUM' ? '多图' : '图片';
});

const postedAt = computed(() => (props.media?.posted_at
    ? new Date(props.media.posted_at).toLocaleString('zh-CN', { hour12: false })
    : null));

// 每次换帖子都要重新等 iframe 加载，否则会沿用上一条的加载态。
watch(() => props.media?.id, () => { loaded.value = false; });

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape' && open.value) emit('close');
};

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    document.documentElement.style.overflow = '';
});

// 弹窗打开时锁住底层滚动，关掉再放开。
watch(open, isOpen => {
    document.documentElement.style.overflow = isOpen ? 'hidden' : '';
    if (isOpen) {
        // 焦点移进弹窗，键盘用户按 Esc / Tab 才有意义。
        window.setTimeout(() => dialog.value?.focus(), 0);
    }
});
</script>

<template>
    <div
        v-if="open && media"
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-4"
        role="dialog"
        aria-modal="true"
        aria-label="Instagram 帖子预览"
        @click.self="emit('close')"
    >
        <div
            ref="dialog"
            class="max-h-full w-full max-w-lg overflow-y-auto rounded-2xl bg-white shadow-xl outline-none"
            tabindex="-1"
        >
            <header class="flex items-start justify-between gap-3 border-b border-slate-100 p-4">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="shrink-0 whitespace-nowrap rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600">
                            {{ typeLabel }}
                        </span>
                        <span v-if="postedAt" class="whitespace-nowrap text-xs text-slate-500">{{ postedAt }}</span>
                    </div>
                    <p v-if="media.caption" class="mt-1.5 line-clamp-2 text-sm text-slate-600">{{ media.caption }}</p>
                </div>
                <button
                    type="button"
                    class="shrink-0 whitespace-nowrap rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                    @click="emit('close')"
                >
                    关闭
                </button>
            </header>

            <div class="p-4">
                <div v-if="embedUrl" class="relative overflow-hidden rounded-xl bg-slate-100" style="aspect-ratio: 4 / 5;">
                    <p
                        v-if="!loaded"
                        class="absolute inset-0 flex items-center justify-center text-sm text-slate-500"
                    >
                        正在从 Instagram 加载帖子…
                    </p>
                    <iframe
                        :key="media.id"
                        :src="embedUrl"
                        class="size-full"
                        title="Instagram 帖子"
                        loading="lazy"
                        scrolling="no"
                        frameborder="0"
                        allowtransparency="true"
                        allow="encrypted-media; clipboard-write; picture-in-picture"
                        referrerpolicy="strict-origin-when-cross-origin"
                        @load="loaded = true"
                    />
                </div>

                <p v-else class="rounded-xl bg-slate-50 p-5 text-sm text-slate-500">
                    这条内容没有可用的 Instagram 链接，无法预览。
                </p>

                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <a
                        :href="media.permalink"
                        target="_blank"
                        rel="noopener nofollow"
                        class="shrink-0 whitespace-nowrap rounded-xl border border-slate-300 px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                    >
                        在 Instagram 打开
                    </a>
                    <span v-if="media.products.length" class="text-xs text-slate-500">
                        关联商品：{{ media.products.map(product => product.title).join('、') }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
