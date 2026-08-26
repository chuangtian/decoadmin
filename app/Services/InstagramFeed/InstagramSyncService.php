<?php

namespace App\Services\InstagramFeed;

use App\Exceptions\InstagramFeedException;
use App\Models\InstagramAccount;
use App\Models\InstagramMedia;
use App\Models\Store;
use Illuminate\Support\Str;

/**
 * 一次完整同步：拉取 Instagram 媒体 → 差量落库 → 推进 R2 转存。
 *
 * 默认把账号里的内容全部翻完。拉元数据很便宜（一页 50 条），真正慢的是转存，
 * 那部分本来就是分批的：媒体多的时候 pending 不会一次清空，再点几次
 * 「刷新转存进度」即可。
 */
class InstagramSyncService
{
    /** SQL 单条语句的占位符有上限，批量插入按这个大小分片。 */
    private const INSERT_CHUNK = 200;

    public function __construct(
        private InstagramProviderService $providers,
        private InstagramMirrorService $mirror,
    ) {}

    /**
     * @return array{fetched: int, created: int, updated: int, ready: int, failed: int, pending: int, configured: bool}
     */
    public function sync(Store $store, ?int $maxItems = null): array
    {
        $account = $store->instagramAccount;
        if (! $account) {
            throw new InstagramFeedException('INSTAGRAM_ACCOUNT_NOT_CONNECTED', '还没有连接 Instagram 账号。', 409);
        }
        if (! $account->isUsable()) {
            throw new InstagramFeedException(
                'INSTAGRAM_ACCOUNT_NOT_READY',
                '授权还没完成，请先选择要连接的 Instagram 账号。',
                409,
            );
        }

        $items = $this->providers->fetchAccountMedia($account, $maxItems);

        // 一次把已有记录全查出来建索引。逐条查库在拉全量（动辄上千条）时
        // 会退化成上千次串行查询。
        $existing = InstagramMedia::query()
            ->where('store_id', $store->id)
            ->get([
                'id', 'ig_media_id', 'mirror_status', 'media_type', 'media_product_type',
                'caption', 'permalink', 'like_count', 'comments_count',
            ])
            ->keyBy('ig_media_id');

        $toCreate = [];
        $toUpdate = [];

        foreach ($items as $item) {
            if ($item['ig_media_id'] === '' || $item['permalink'] === '') {
                continue;
            }

            $current = $existing->get($item['ig_media_id']);
            if (! $current) {
                $toCreate[] = $item;

                continue;
            }

            // 已转存成功的不再重传；失败的用新链接重试。
            $retryMirror = $current->mirror_status === 'failed';

            /*
             * 已经转存好的条目不需要刷新 ig_media_url / ig_thumbnail_url —— 那两个地址
             * 每次同步都带新签名，比来比去永远「有变化」，会让每次同步都退化成上千次
             * 无意义的写入。这类条目只在元数据真的变了时才更新。
             * 没转存完的必须更新：旧签名地址会过期，转存要用新的。
             */
            $mirrored = $current->mirror_status === 'ready';
            $metaChanged = $current->media_type !== $item['media_type']
                || $current->media_product_type !== $item['media_product_type']
                || $current->caption !== $item['caption']
                || $current->permalink !== $item['permalink']
                || $current->like_count !== $item['like_count']
                || $current->comments_count !== $item['comments_count'];

            if ($mirrored && ! $metaChanged && ! $retryMirror) {
                continue;
            }

            $toUpdate[] = ['id' => $current->id, 'item' => $item, 'retry_mirror' => $retryMirror];
        }

        $this->insertNew($store, $account, $toCreate);
        $this->applyUpdates($toUpdate);

        $mirror = $this->mirror->mirrorPending($store);
        $account->forceFill(['last_synced_at' => now()])->save();

        return [
            'fetched' => count($items),
            'created' => count($toCreate),
            'updated' => count($toUpdate),
            ...$mirror,
        ];
    }

    /** 只推进转存，用于「刷新转存进度」按钮和失败重试。 */
    public function advanceMirrors(Store $store): array
    {
        return $this->mirror->mirrorPending($store);
    }

    /** 把单条媒体重新排进转存队列。 */
    public function retryMirror(InstagramMedia $media): void
    {
        $media->forceFill(['mirror_status' => 'pending', 'mirror_error' => null])->save();
    }

    /** @param list<array<string, mixed>> $items */
    private function insertNew(Store $store, InstagramAccount $account, array $items): void
    {
        if ($items === []) {
            return;
        }

        $now = now();
        foreach (array_chunk($items, self::INSERT_CHUNK) as $chunk) {
            $rows = array_map(fn (array $item): array => [
                'uuid' => (string) Str::uuid(),
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'account_id' => $account->id,
                'ig_media_id' => $item['ig_media_id'],
                'media_type' => $item['media_type'],
                'media_product_type' => $item['media_product_type'],
                'caption' => $item['caption'],
                'permalink' => $item['permalink'],
                'ig_media_url' => $item['media_url'],
                'ig_thumbnail_url' => $item['thumbnail_url'],
                'posted_at' => $item['posted_at'],
                'like_count' => $item['like_count'],
                'comments_count' => $item['comments_count'],
                'mirror_status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk);

            // 并发同步可能撞上唯一键，忽略重复而不是整批失败。
            InstagramMedia::query()->insertOrIgnore($rows);
        }
    }

    /** @param list<array{id: int, item: array<string, mixed>, retry_mirror: bool}> $updates */
    private function applyUpdates(array $updates): void
    {
        foreach ($updates as $update) {
            $item = $update['item'];
            $values = [
                'media_type' => $item['media_type'],
                'media_product_type' => $item['media_product_type'],
                'caption' => $item['caption'],
                'permalink' => $item['permalink'],
                'ig_media_url' => $item['media_url'],
                'ig_thumbnail_url' => $item['thumbnail_url'],
                'posted_at' => $item['posted_at'],
                'like_count' => $item['like_count'],
                'comments_count' => $item['comments_count'],
                'updated_at' => now(),
            ];
            if ($update['retry_mirror']) {
                $values['mirror_status'] = 'pending';
                $values['mirror_error'] = null;
            }

            InstagramMedia::query()->whereKey($update['id'])->update($values);
        }
    }
}
