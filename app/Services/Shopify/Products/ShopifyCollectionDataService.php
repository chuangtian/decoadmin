<?php

namespace App\Services\Shopify\Products;

use App\Exceptions\ShopifyApiException;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ShopifyCollectionDataService
{
    /**
     * @param  array<string, mixed>  $collectionNode
     * @return array{collection: ProductCollection, created: bool}
     */
    public function upsert(Store $store, array $collectionNode, string $syncBatch): array
    {
        $shopifyCollectionId = $this->numericId($collectionNode['id'] ?? null, 'Collection');
        $collection = ProductCollection::query()->firstOrNew([
            'store_id' => $store->getKey(),
            'shopify_collection_id' => $shopifyCollectionId,
        ]);
        $created = ! $collection->exists;

        $collection->fill([
            'organization_id' => $store->organization_id,
            'title' => $this->requiredString($collectionNode, 'title'),
            'handle' => $this->requiredString($collectionNode, 'handle'),
            'sort_order' => $this->normalizedString($collectionNode['sortOrder'] ?? null),
            'updated_at_shopify' => $this->nullableDate($collectionNode['updatedAt'] ?? null),
            'sync_batch' => $syncBatch,
            'synced_at' => now(),
        ])->save();

        return ['collection' => $collection, 'created' => $created];
    }

    /**
     * Persist one bounded Shopify product-ID page for a collection.
     *
     * Unresolved products remain linked by their Shopify ID and are resolved when
     * the shared product snapshot arrives, avoiding race-dependent data loss.
     *
     * @param  list<mixed>  $productGids
     * @return array{members: int, unresolved: int}
     */
    public function syncMembershipPage(
        Store $store,
        ProductCollection $collection,
        array $productGids,
        string $syncBatch,
    ): array {
        $productIds = collect($productGids)
            ->map(fn (mixed $gid): string => $this->numericId($gid, 'Product'))
            ->unique()
            ->values();
        $localProducts = Product::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->whereIn('shopify_product_id', $productIds)
            ->pluck('id', 'shopify_product_id');
        $now = now();
        $rows = $productIds->map(fn (string $shopifyProductId): array => [
            'organization_id' => $store->organization_id,
            'store_id' => $store->getKey(),
            'product_collection_id' => $collection->getKey(),
            'product_id' => $localProducts->get($shopifyProductId),
            'shopify_product_id' => $shopifyProductId,
            'sync_batch' => $syncBatch,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($rows !== []) {
            DB::table('product_collection_memberships')->upsert(
                $rows,
                ['product_collection_id', 'shopify_product_id'],
                ['organization_id', 'store_id', 'product_id', 'sync_batch', 'updated_at'],
            );
        }

        return [
            'members' => $productIds->count(),
            'unresolved' => $productIds->filter(fn (string $id): bool => ! $localProducts->has($id))->count(),
        ];
    }

    public function completeMembershipSync(ProductCollection $collection, string $syncBatch): int
    {
        return DB::table('product_collection_memberships')
            ->where('product_collection_id', $collection->getKey())
            ->where('sync_batch', '!=', $syncBatch)
            ->delete();
    }

    public function resolveProduct(Store $store, Product $product): int
    {
        return DB::table('product_collection_memberships')
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->getKey())
            ->where('shopify_product_id', $product->shopify_product_id)
            ->update(['product_id' => $product->getKey(), 'updated_at' => now()]);
    }

    public function deleteProductMemberships(Store $store, string $shopifyProductId): int
    {
        return DB::table('product_collection_memberships')
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->getKey())
            ->where('shopify_product_id', $shopifyProductId)
            ->delete();
    }

    public function delete(Store $store, mixed $collectionGid): int
    {
        return ProductCollection::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->where('shopify_collection_id', $this->numericId($collectionGid, 'Collection'))
            ->delete();
    }

    public function prune(Store $store, string $syncBatch): int
    {
        return ProductCollection::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->where(fn ($query) => $query->whereNull('sync_batch')->orWhere('sync_batch', '!=', $syncBatch))
            ->delete();
    }

    private function numericId(mixed $gid, string $resource): string
    {
        if (! is_string($gid) || preg_match("#^gid://shopify/{$resource}/([0-9]+)$#", $gid, $matches) !== 1) {
            throw new ShopifyApiException("Shopify {$resource} ID 格式无效。");
        }

        return $matches[1];
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new ShopifyApiException("Shopify Collection 数据缺少字段 [{$key}]。");
        }

        return trim($value);
    }

    private function normalizedString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? strtolower(trim($value)) : null;
    }

    private function nullableDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new ShopifyApiException('Shopify Collection 更新时间格式无效。');
        }

        try {
            return CarbonImmutable::parse($value)->utc()->toDateTimeString();
        } catch (\Throwable) {
            throw new ShopifyApiException('Shopify Collection 更新时间格式无效。');
        }
    }
}
