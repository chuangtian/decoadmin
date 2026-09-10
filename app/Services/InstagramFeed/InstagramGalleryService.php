<?php

namespace App\Services\InstagramFeed;

use App\Exceptions\InstagramFeedException;
use App\Models\AuditLog;
use App\Models\InstagramGallery;
use App\Models\InstagramGalleryItem;
use App\Models\InstagramMedia;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 展示组的编排层。
 *
 * 一个组 = 商家自己命名的一批媒体 + 组内顺序，对应前台一个 widget 要展示的内容。
 * 同一条媒体可以进多个组、各组顺序互不影响，所以成员关系放在
 * instagram_gallery_items 上，而不是在媒体行上挂 enabled / position。
 */
class InstagramGalleryService
{
    public function create(Store $store, string $rawName, ?User $actor): InstagramGallery
    {
        $name = $this->normalizeName($rawName);

        $gallery = DB::transaction(function () use ($store, $name, $actor): InstagramGallery {
            $lastPosition = (int) InstagramGallery::query()
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->max('position');

            return InstagramGallery::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'name' => $name,
                'handle' => $this->uniqueHandle($store),
                'position' => $lastPosition + 1,
                'created_by' => $actor?->getKey(),
            ]);
        });

        $this->audit($store, $gallery, $actor, 'instagram_feed_gallery_created');

        return $gallery;
    }

    public function rename(Store $store, InstagramGallery $gallery, string $rawName, ?User $actor): InstagramGallery
    {
        $this->assertOwnership($store, $gallery);
        $name = $this->normalizeName($rawName);
        if ($gallery->name === $name) {
            return $gallery;
        }

        // 只改 name。handle 是随机哈希、与名字无关，改名不会影响主题里的引用。
        $previous = $gallery->name;
        $gallery->forceFill(['name' => $name])->save();
        $this->audit($store, $gallery, $actor, 'instagram_feed_gallery_renamed', ['previous_name' => $previous]);

        return $gallery;
    }

    public function delete(Store $store, InstagramGallery $gallery, ?User $actor): void
    {
        $this->assertOwnership($store, $gallery);
        $snapshot = ['gallery_handle' => $gallery->handle, 'gallery_name' => $gallery->name];
        // 成员靠外键级联清掉。
        $gallery->delete();

        AuditLog::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'user_id' => $actor?->getKey(),
            'action' => 'instagram_feed_gallery_deleted',
            'subject_type' => InstagramGallery::class,
            'subject_id' => $gallery->id,
            'metadata' => [
                'scope' => 'store',
                'environment' => (string) config('instagram_feed.environment'),
                'actor_type' => $actor ? 'user' : 'shopify_app_session',
                'shop_domain' => $store->shopify_domain,
                ...$snapshot,
            ],
        ]);
    }

    /**
     * 往组里加成员，接在当前末尾。
     *
     * 已经在组里的会被跳过（唯一键兜底），所以重复点「加入」不会产生重复项。
     * 只接受属于本店铺的媒体，挡住伪造的 id。
     *
     * @param  list<string>  $mediaUuids
     */
    public function addItems(Store $store, InstagramGallery $gallery, array $mediaUuids, ?User $actor): int
    {
        $this->assertOwnership($store, $gallery);
        $ordered = $this->ownedMediaIds($store, $mediaUuids);
        if ($ordered === []) {
            return 0;
        }

        $added = DB::transaction(function () use ($gallery, $ordered): int {
            $existing = InstagramGalleryItem::query()
                ->where('gallery_id', $gallery->id)
                ->lockForUpdate()
                ->get(['media_id', 'position']);
            $alreadyIn = $existing->pluck('media_id')->all();
            $position = (int) $existing->max('position');

            // 保留调用方传进来的顺序，也就是用户勾选的顺序。
            $rows = [];
            foreach ($ordered as $mediaId) {
                if (in_array($mediaId, $alreadyIn, true)) {
                    continue;
                }
                $rows[] = [
                    'gallery_id' => $gallery->id,
                    'media_id' => $mediaId,
                    'position' => ++$position,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            if ($rows === []) {
                return 0;
            }
            InstagramGalleryItem::query()->insertOrIgnore($rows);

            return count($rows);
        });

        if ($added > 0) {
            $this->audit($store, $gallery, $actor, 'instagram_feed_gallery_items_added', ['added' => $added]);
        }

        return $added;
    }

    /** @param list<string> $mediaUuids */
    public function removeItems(Store $store, InstagramGallery $gallery, array $mediaUuids, ?User $actor): int
    {
        $this->assertOwnership($store, $gallery);
        $mediaIds = $this->ownedMediaIds($store, $mediaUuids);
        if ($mediaIds === []) {
            return 0;
        }

        $removed = InstagramGalleryItem::query()
            ->where('gallery_id', $gallery->id)
            ->whereIn('media_id', $mediaIds)
            ->delete();

        if ($removed > 0) {
            $this->audit($store, $gallery, $actor, 'instagram_feed_gallery_items_removed', ['removed' => $removed]);
        }

        return $removed;
    }

    /**
     * 重排组内顺序。
     *
     * 前端算好完整顺序整串传进来，这里按下标重写 position（1..N），不做相对移动 ——
     * 拖拽和键盘上下移都走这条路径，服务端只认最终顺序。
     *
     * @param  list<string>  $mediaUuids
     */
    public function reorder(Store $store, InstagramGallery $gallery, array $mediaUuids, ?User $actor): int
    {
        $this->assertOwnership($store, $gallery);
        $mediaIds = $this->ownedMediaIds($store, $mediaUuids);
        if ($mediaIds === []) {
            throw new InstagramFeedException('GALLERY_REORDER_EMPTY', '没有可排序的内容。', 422);
        }

        $updated = DB::transaction(function () use ($gallery, $mediaIds): int {
            $count = 0;
            foreach ($mediaIds as $index => $mediaId) {
                $count += InstagramGalleryItem::query()
                    ->where('gallery_id', $gallery->id)
                    ->where('media_id', $mediaId)
                    ->update(['position' => $index + 1, 'updated_at' => now()]);
            }

            return $count;
        });

        $this->audit($store, $gallery, $actor, 'instagram_feed_gallery_reordered', ['items' => $updated]);

        return $updated;
    }

    /**
     * 保存媒体关联的商品 GID。
     *
     * 只接受 Product 的 GID，挡住误传的 variant / collection。
     *
     * @param  list<string>  $productGids
     */
    public function setMediaProducts(Store $store, InstagramMedia $media, array $productGids, ?User $actor): void
    {
        if ((int) $media->store_id !== (int) $store->id) {
            throw new InstagramFeedException('INSTAGRAM_MEDIA_NOT_FOUND', '找不到这条 Instagram 媒体。', 404);
        }

        $valid = [];
        foreach ($productGids as $gid) {
            if (! is_string($gid) || preg_match('#^gid://shopify/Product/\d+$#', $gid) !== 1) {
                throw new InstagramFeedException('INVALID_PRODUCT_GID', '商品标识格式无效。', 422);
            }
            $valid[] = $gid;
        }
        $valid = array_values(array_unique($valid));
        if (count($valid) > 20) {
            throw new InstagramFeedException('TOO_MANY_LINKED_PRODUCTS', '单条内容最多关联 20 个商品。', 422);
        }

        $media->forceFill(['product_ids' => $valid === [] ? null : $valid])->save();

        AuditLog::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'user_id' => $actor?->getKey(),
            'action' => 'instagram_feed_media_products_updated',
            'subject_type' => InstagramMedia::class,
            'subject_id' => $media->id,
            'metadata' => [
                'scope' => 'store',
                'environment' => (string) config('instagram_feed.environment'),
                'actor_type' => $actor ? 'user' : 'shopify_app_session',
                'shop_domain' => $store->shopify_domain,
                'ig_media_id' => $media->ig_media_id,
                'products' => count($valid),
            ],
        ]);
    }

    private function assertOwnership(Store $store, InstagramGallery $gallery): void
    {
        if ((int) $gallery->store_id !== (int) $store->id) {
            throw new InstagramFeedException('GALLERY_NOT_FOUND', '找不到这个展示组。', 404);
        }
    }

    /**
     * 把 uuid 列表翻译成本店铺的媒体主键，并保留调用方的顺序。
     *
     * @param  list<string>  $mediaUuids
     * @return list<int>
     */
    private function ownedMediaIds(Store $store, array $mediaUuids): array
    {
        $uuids = array_values(array_unique(array_filter(
            $mediaUuids,
            fn (mixed $uuid): bool => is_string($uuid) && $uuid !== '',
        )));
        if ($uuids === []) {
            return [];
        }
        if (count($uuids) > 500) {
            throw new InstagramFeedException('TOO_MANY_GALLERY_ITEMS', '单次最多处理 500 条内容。', 422);
        }

        $owned = InstagramMedia::query()
            ->where('store_id', $store->id)
            ->whereIn('uuid', $uuids)
            ->pluck('id', 'uuid');

        $ids = [];
        foreach ($uuids as $uuid) {
            if ($owned->has($uuid)) {
                $ids[] = (int) $owned->get($uuid);
            }
        }

        return $ids;
    }

    private function normalizeName(string $raw): string
    {
        $name = trim($raw);
        $maxLength = (int) config('instagram_feed.gallery.max_name_length', 60);
        if ($name === '') {
            throw new InstagramFeedException('GALLERY_NAME_REQUIRED', '组名不能为空。', 422);
        }
        if (mb_strlen($name) > $maxLength) {
            throw new InstagramFeedException('GALLERY_NAME_TOO_LONG', "组名不能超过 {$maxLength} 个字。", 422);
        }

        return $name;
    }

    /**
     * handle 用随机短哈希，不从组名派生。
     *
     * 从名字派生有两个问题：中文名派生不出可读 handle；名字和 handle 一旦有关联，
     * 用户改名时会本能地期待 handle 跟着变，但 handle 已被主题设置引用，一变前台
     * 就找不到组。随机哈希把两者彻底解耦，改名永远不影响前台。
     */
    private function uniqueHandle(Store $store): string
    {
        $bytes = max(3, (int) config('instagram_feed.gallery.handle_bytes', 4));

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $handle = bin2hex(random_bytes($bytes));
            $taken = InstagramGallery::query()
                ->where('store_id', $store->id)
                ->where('handle', $handle)
                ->exists();
            if (! $taken) {
                return $handle;
            }
        }

        throw new InstagramFeedException('GALLERY_HANDLE_GENERATION_FAILED', '生成展示组标识失败，请重试。', 500);
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Store $store, InstagramGallery $gallery, ?User $actor, string $action, array $metadata = []): void
    {
        AuditLog::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'user_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => InstagramGallery::class,
            'subject_id' => $gallery->id,
            'metadata' => [
                'scope' => 'store',
                'environment' => (string) config('instagram_feed.environment'),
                // user_id 为空时要能看出是谁动的：Shopify 内嵌页面的操作者是店铺员工，
                // 只能按店铺追溯，所以把店铺域名一并记下来。
                'actor_type' => $actor ? 'user' : 'shopify_app_session',
                'shop_domain' => $store->shopify_domain,
                'gallery_handle' => $gallery->handle,
                ...$metadata,
            ],
        ]);
    }
}
