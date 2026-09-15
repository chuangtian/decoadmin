<?php

namespace App\Services\Personalization;

use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class PersonalizationCatalogService
{
    /**
     * Merchant picker projection. Unlike storefront candidates this includes
     * draft, archived, unpublished, and unavailable products so the editor can
     * show their real state instead of silently hiding a previous selection.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function pickerProducts(Store $store, int $limit = 250): Collection
    {
        $limit = min(250, max(1, $limit));

        return Product::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->with([
                'collections' => fn ($query) => $query
                    ->select(['product_collections.id', 'shopify_collection_id', 'title', 'handle'])
                    ->orderBy('title'),
                'variants' => fn ($query) => $query
                    ->select([
                        'id', 'product_id', 'shopify_variant_id', 'title', 'sku', 'price',
                        'compare_at_price', 'available_for_sale', 'selected_options', 'image_url',
                        'image_alt', 'image_width', 'image_height',
                    ])
                    ->orderBy('id'),
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (Product $product) use ($store): array {
                $payload = $this->productPayload($product, $store);
                $available = $product->status === 'active'
                    && $product->published_at_shopify !== null
                    && $product->variants->contains(fn ($variant): bool => (bool) $variant->available_for_sale);

                return [
                    ...$payload,
                    'status' => (string) $product->status,
                    'available_for_sale' => $available,
                    'availability_label' => match (true) {
                        $product->status === 'archived' => 'Archived',
                        $product->status !== 'active' => 'Draft',
                        $product->published_at_shopify === null => 'Unpublished',
                        ! $available => 'Out of stock or unavailable',
                        default => 'Enabled',
                    },
                ];
            });
    }

    /** @return Collection<int, array{shopify_collection_id: string, title: string, handle: string, sort_order: ?string, product_count: int}> */
    public function collections(Store $store, int $limit = 200): Collection
    {
        $limit = min(500, max(1, $limit));

        return ProductCollection::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->withCount('products')
            ->orderBy('title')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (ProductCollection $collection): array => [
                'shopify_collection_id' => (string) $collection->shopify_collection_id,
                'title' => $collection->title,
                'handle' => $collection->handle,
                'sort_order' => $collection->sort_order,
                'product_count' => (int) $collection->products_count,
            ]);
    }

    /**
     * Return a bounded, storefront-safe product projection for recommendation algorithms.
     *
     * @param  array{
     *   tags?: list<string>,
     *   collection_ids?: list<int|string>,
     *   product_ids?: list<int|string>,
     *   exclude_product_ids?: list<int|string>,
     *   min_price?: int|float|string|null,
     *   max_price?: int|float|string|null,
     *   in_stock_only?: bool,
     *   limit?: int
     * }  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function candidates(Store $store, array $filters = []): Collection
    {
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 24)));
        $tags = $this->strings($filters['tags'] ?? []);
        $collectionIds = $this->numericIds($filters['collection_ids'] ?? []);
        $productIds = $this->numericIds($filters['product_ids'] ?? []);
        $excludedProductIds = $this->numericIds($filters['exclude_product_ids'] ?? []);
        $minPrice = $this->money($filters['min_price'] ?? null, 'min_price');
        $maxPrice = $this->money($filters['max_price'] ?? null, 'max_price');
        $inStockOnly = (bool) ($filters['in_stock_only'] ?? true);

        if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
            throw new InvalidArgumentException('The minimum price cannot exceed the maximum price.');
        }

        $variantFilter = function (Builder $query) use ($minPrice, $maxPrice, $inStockOnly): void {
            $query
                ->when($minPrice !== null, fn (Builder $query) => $query->where('price', '>=', $minPrice))
                ->when($maxPrice !== null, fn (Builder $query) => $query->where('price', '<=', $maxPrice))
                ->when($inStockOnly, fn (Builder $query) => $query->where('available_for_sale', true));
        };

        $query = Product::query()
            ->forOrganization($store->organization_id)
            ->forStore($store)
            ->where('status', 'active')
            ->whereNotNull('published_at_shopify')
            ->when($productIds !== [], fn (Builder $query) => $query
                ->whereIn('shopify_product_id', $productIds))
            ->when($excludedProductIds !== [], fn (Builder $query) => $query
                ->whereNotIn('shopify_product_id', $excludedProductIds))
            ->when($collectionIds !== [], fn (Builder $query) => $query
                ->whereHas('collections', fn (Builder $query) => $query
                    ->where('product_collections.organization_id', $store->organization_id)
                    ->where('product_collections.store_id', $store->getKey())
                    ->whereIn('product_collections.shopify_collection_id', $collectionIds)))
            ->whereHas('variants', $variantFilter);

        foreach ($tags as $tag) {
            $query->whereJsonContains('tags', $tag);
        }

        return $query
            ->with([
                'collections' => fn ($query) => $query
                    ->select(['product_collections.id', 'shopify_collection_id', 'title', 'handle'])
                    ->orderBy('title'),
                'variants' => fn ($query) => $query
                    ->select([
                        'id', 'product_id', 'shopify_variant_id', 'title', 'sku', 'price',
                        'compare_at_price', 'available_for_sale', 'selected_options', 'image_url',
                        'image_alt', 'image_width', 'image_height',
                    ])
                    ->where($variantFilter)
                    ->orderBy('id'),
            ])
            ->orderByDesc('published_at_shopify')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Product $product): array => $this->productPayload($product, $store));
    }

    /** @return array<string, mixed> */
    private function productPayload(Product $product, Store $store): array
    {
        $prices = $product->variants->pluck('price')->filter()->map(fn ($price): string => (string) $price);

        return [
            'id' => $product->getKey(),
            'shopify_product_id' => (string) $product->shopify_product_id,
            'title' => $product->title,
            'handle' => $product->handle,
            'vendor' => $product->vendor,
            'product_type' => $product->product_type,
            'tags' => array_values($product->tags ?? []),
            'published_at' => $product->published_at_shopify?->toIso8601String(),
            'storefront' => [
                'url' => $product->online_store_url,
                'path' => '/products/'.$product->handle,
                'image' => [
                    'url' => $product->featured_image_url,
                    'alt' => $product->featured_image_alt,
                    'width' => $product->featured_image_width,
                    'height' => $product->featured_image_height,
                ],
            ],
            'price' => [
                'currency' => $store->currency,
                'minimum' => $prices->isEmpty() ? null : $prices->sort(SORT_NUMERIC)->first(),
                'maximum' => $prices->isEmpty() ? null : $prices->sort(SORT_NUMERIC)->last(),
            ],
            'collections' => $product->collections->map(fn ($collection): array => [
                'shopify_collection_id' => (string) $collection->shopify_collection_id,
                'title' => $collection->title,
                'handle' => $collection->handle,
            ])->values()->all(),
            'variants' => $product->variants->map(fn ($variant): array => [
                'shopify_variant_id' => (string) $variant->shopify_variant_id,
                'title' => $variant->title,
                'sku' => $variant->sku,
                'price' => (string) $variant->price,
                'compare_at_price' => $variant->compare_at_price !== null ? (string) $variant->compare_at_price : null,
                'available_for_sale' => (bool) $variant->available_for_sale,
                'selected_options' => array_values($variant->selected_options ?? []),
                'image' => [
                    'url' => $variant->image_url,
                    'alt' => $variant->image_alt,
                    'width' => $variant->image_width,
                    'height' => $variant->image_height,
                ],
            ])->values()->all(),
        ];
    }

    /** @return list<string> */
    private function strings(mixed $values): array
    {
        if (! is_array($values)) {
            throw new InvalidArgumentException('The tag filter is invalid.');
        }

        return collect($values)
            ->map(fn ($value): string => is_string($value) ? trim($value) : '')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function numericIds(mixed $values): array
    {
        if (! is_array($values)) {
            throw new InvalidArgumentException('The Shopify ID filter is invalid.');
        }

        return collect($values)->map(function ($value): string {
            $value = is_int($value) || is_string($value) ? (string) $value : '';

            if (preg_match('/^[0-9]+$/', $value) !== 1) {
                throw new InvalidArgumentException('The Shopify ID filter is invalid.');
            }

            return $value;
        })->unique()->values()->all();
    }

    private function money(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_scalar($value) || ! is_numeric((string) $value) || (float) $value < 0) {
            throw new InvalidArgumentException("The price filter [{$field}] is invalid.");
        }

        return (string) $value;
    }
}
