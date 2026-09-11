<?php

namespace App\Services\InstagramFeed;

use App\Models\InstagramMedia;
use App\Models\Store;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Instagram CDN 的媒体链接带签名，几小时到几天就会失效，直接放到店铺前台一定会挂。
 * 所以同步完立刻把文件搬到 Cloudflare R2，对外用绑定在桶上的自定义域名给永久地址。
 *
 * 状态机：pending → processing → ready / failed。
 * processing 是占位状态，进程中断会留在这里，超过 stale 阈值后下一轮重新捞出来。
 */
class InstagramMirrorService
{
    public function __construct(private R2Client $r2) {}

    /**
     * 把还没转存的媒体搬到 R2。
     *
     * 逐条串行处理：转存要落临时文件再上传，并发会同时占用多份磁盘与带宽。
     *
     * @return array{ready: int, failed: int, pending: int, configured: bool}
     */
    public function mirrorPending(Store $store, ?int $limit = null): array
    {
        if (! $this->r2->isConfigured()) {
            return ['ready' => 0, 'failed' => 0, 'pending' => $this->queuedCount($store), 'configured' => false];
        }

        $batchSize = $limit ?? (int) config('instagram_feed.mirror.batch_size', 10);
        $staleBefore = now()->subSeconds((int) config('instagram_feed.mirror.stale_processing_seconds', 300));

        $queue = InstagramMedia::query()
            ->where('store_id', $store->id)
            ->where(function ($query) use ($staleBefore): void {
                $query->where('mirror_status', 'pending')
                    // 上次跑到一半挂掉的，超时后重试。
                    ->orWhere(fn ($stale) => $stale->where('mirror_status', 'processing')
                        ->where('updated_at', '<', $staleBefore));
            })
            ->orderByDesc('posted_at')
            ->limit($batchSize)
            ->get();

        $ready = 0;
        $failed = 0;

        foreach ($queue as $media) {
            // 先占位。进程在这之后挂掉，这条会留在 processing，靠上面的超时判断重试。
            $media->forceFill(['mirror_status' => 'processing', 'mirror_error' => null])->save();

            try {
                $this->mirrorOne($store, $media);
                $ready++;
            } catch (Throwable $exception) {
                $failed++;
                $media->forceFill([
                    'mirror_status' => 'failed',
                    'mirror_error' => $this->safeFailureReason($exception),
                ])->save();
            }
        }

        return [
            'ready' => $ready,
            'failed' => $failed,
            'pending' => $this->queuedCount($store),
            'configured' => true,
        ];
    }

    /**
     * 失败原因要落库并展示给商家，所以必须先脱敏。
     *
     * HTTP 客户端的异常消息通常带完整请求地址：Instagram 的媒体地址是带签名的，
     * R2 端点含账号标识，预签名地址还会带 X-Amz-Credential。这些都不该出现在
     * 商家看到的日志里，所以统一去掉查询串，并抹掉常见的令牌与凭证片段。
     */
    private function safeFailureReason(Throwable $exception): string
    {
        $reason = $exception->getMessage();

        // 去掉所有 URL 的查询串（签名、凭证都在这里）。
        $reason = preg_replace('#(https?://[^\s?"\']+)\?[^\s"\']*#i', '$1', $reason) ?? $reason;
        // 兜底抹掉可能夹带的令牌与访问密钥。
        $reason = preg_replace('/\b(shp(at|ca|pa|ss)_[A-Za-z0-9]+)\b/', '[redacted]', $reason) ?? $reason;
        $reason = preg_replace('/\b(AKIA|ASIA)[A-Z0-9]{8,}\b/', '[redacted]', $reason) ?? $reason;
        $reason = preg_replace('/(?i)\b(x-amz-credential|x-amz-signature|access[_-]?key|secret)\b\S*/', '[redacted]', $reason) ?? $reason;

        return mb_substr(trim($reason), 0, 500);
    }

    /**
     * 清空一个店铺的转存产物：先删 R2 对象，再删本地记录。
     *
     * 断开授权、卸载应用、Meta 数据删除请求都走这里，否则 R2 里会留下永远不会被
     * 访问到的对象，一直计存储费。
     *
     * 顺序很重要：对象 key 只存在数据库里，先删记录就再也找不到这些对象了。反过来
     * 先删对象、中途失败的话，记录还在，重跑一次可以接着删。
     *
     * R2 删除失败不阻断数据库清理：清理都是用户主动触发的动作，不能因为对象存储
     * 抽风就把授权记录卡在半删状态。失败的 key 会记日志，需要时人工清。
     *
     * @return array{records: int, objects: int, failed_keys: list<string>}
     */
    public function purgeStoreMedia(Store $store): array
    {
        $keys = InstagramMedia::query()
            ->where('store_id', $store->id)
            ->get(['video_key', 'poster_key'])
            ->flatMap(fn (InstagramMedia $media): array => [$media->video_key, $media->poster_key])
            ->filter(fn (mixed $key): bool => is_string($key) && $key !== '')
            ->unique()
            ->values()
            ->all();

        $failedKeys = [];
        // R2 没配置说明压根没搬过，没有对象要删。
        if ($keys !== [] && $this->r2->isConfigured()) {
            $failedKeys = $this->r2->deleteObjects($keys);
            if ($failedKeys !== []) {
                Log::warning('Instagram feed R2 objects could not be deleted and need manual cleanup.', [
                    'store_id' => $store->id,
                    'failed' => count($failedKeys),
                    'total' => count($keys),
                ]);
            }
        }

        // 展示组成员靠外键级联清掉。
        $records = InstagramMedia::query()->where('store_id', $store->id)->delete();

        return ['records' => $records, 'objects' => count($keys), 'failed_keys' => $failedKeys];
    }

    private function mirrorOne(Store $store, InstagramMedia $media): void
    {
        $isVideo = $media->isVideo();
        $videoSource = $isVideo ? $media->ig_media_url : null;
        // 视频用 IG 的缩略图当封面；图片本身就是封面。
        $posterSource = $isVideo
            ? $media->ig_thumbnail_url
            : ($media->ig_media_url ?: $media->ig_thumbnail_url);

        if ($isVideo && blank($videoSource)) {
            throw new \RuntimeException('Instagram 没有返回视频地址。');
        }
        if (! $isVideo && blank($posterSource)) {
            throw new \RuntimeException('Instagram 没有返回可用的媒体地址。');
        }

        $videoKey = null;
        $posterKey = null;

        if (filled($videoSource)) {
            $videoKey = $this->videoKey($store, $media);
            $this->r2->copyFromUrl((string) $videoSource, $videoKey, 'video/mp4');
        }

        if (filled($posterSource)) {
            $candidate = $this->posterKey($store, $media);
            try {
                $this->r2->copyFromUrl((string) $posterSource, $candidate, 'image/jpeg');
                $posterKey = $candidate;
            } catch (Throwable $exception) {
                // 视频已经搬好了，封面搬不动只是体验降级，不该让整条失败。
                // 前台 <video> 没有 poster 也能播，后台列表会回退到 IG 缩略图。
                if ($videoKey === null) {
                    throw $exception;
                }
                Log::warning('Instagram feed poster mirror failed; keeping the media without a poster.', [
                    'store_id' => $store->id,
                    'ig_media_id' => $media->ig_media_id,
                ]);
            }
        }

        $media->forceFill([
            'mirror_status' => 'ready',
            'video_key' => $videoKey,
            'poster_key' => $posterKey,
            'video_url' => $videoKey ? $this->r2->publicUrl($videoKey) : null,
            'poster_url' => $posterKey ? $this->r2->publicUrl($posterKey) : null,
            'mirror_error' => null,
            'mirrored_at' => now(),
        ])->save();
    }

    /**
     * key 里带 store 做租户隔离，带 ig_media_id 保证同一条媒体重复转存是覆盖而不是堆积。
     * 一条媒体的内容不会变，所以对象可以按 immutable 长缓存。
     */
    private function videoKey(Store $store, InstagramMedia $media): string
    {
        return $this->prefix($store).'/videos/'.$media->ig_media_id.'.mp4';
    }

    private function posterKey(Store $store, InstagramMedia $media): string
    {
        return $this->prefix($store).'/posters/'.$media->ig_media_id.'.jpg';
    }

    private function prefix(Store $store): string
    {
        return (string) config('instagram_feed.environment').'/'.$store->shopify_domain;
    }

    private function queuedCount(Store $store): int
    {
        return InstagramMedia::query()
            ->where('store_id', $store->id)
            ->whereIn('mirror_status', ['pending', 'processing'])
            ->count();
    }
}
