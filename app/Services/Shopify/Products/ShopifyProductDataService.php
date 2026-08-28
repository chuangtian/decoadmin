<?php

namespace App\Services\Shopify\Products;

use App\Exceptions\ShopifyApiException;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ShopifyProductDataService
{
    public function __construct(private ShopifyCollectionDataService $collections) {}

    /**
     * Persist one complete Shopify product snapshot.
     *
     * This entry point is intentionally reusable by future products/create and
     * products/update webhook handlers.
     *
     * @param  array<string, mixed>  $productNode
     * @param  list<array<string, mixed>>  $variantNodes
     * @return array{product: Product, created: bool, variants_created: int, variants_updated: int}
     */
    public function upsert(Store $store, array $productNode, array $variantNodes): array
    {
        return DB::transaction(function () use ($store, $productNode, $variantNodes): array {
            $shopifyProductId = $this->numericId($productNode['id'] ?? null, 'Product');
            $product = Product::query()->firstOrNew([
                'store_id' => $store->getKey(),
                'shopify_product_id' => $shopifyProductId,
            ]);
            $created = ! $product->exists;
            $productAttributes = [
                'organization_id' => $store->organization_id,
                'title' => $this->requiredString($productNode, 'title'),
                'handle' => $this->requiredString($productNode, 'handle'),
                'status' => strtolower($this->requiredString($productNode, 'status')),
                'vendor' => $this->nullableString($productNode['vendor'] ?? null),
                'product_type' => $this->nullableString($productNode['productType'] ?? null),
                'description' => $this->nullableString($productNode['description'] ?? null),
                'synced_at' => now(),
            ];

            if (array_key_exists('tags', $productNode)) {
                $productAttributes['tags'] = $this->tags($productNode['tags']);
            }
            if (array_key_exists('createdAt', $productNode)) {
                $productAttributes['created_at_shopify'] = $this->nullableDate($productNode['createdAt'], 'createdAt');
            }
            if (array_key_exists('publishedAt', $productNode)) {
                $productAttributes['published_at_shopify'] = $this->nullableDate($productNode['publishedAt'], 'publishedAt');
            }
            if (array_key_exists('onlineStoreUrl', $productNode)) {
                $productAttributes['online_store_url'] = $this->nullableString($productNode['onlineStoreUrl']);
            }
            if (array_key_exists('featuredMedia', $productNode)) {
                $productAttributes = [
                    ...$productAttributes,
                    ...$this->imageAttributes($productNode['featuredMedia'], 'featured_image'),
                ];
            }

            $product->fill($productAttributes)->save();
            $this->collections->resolveProduct($store, $product);

            $variantsCreated = 0;
            $variantsUpdated = 0;
            $seenVariantIds = [];

            foreach ($variantNodes as $variantNode) {
                $shopifyVariantId = $this->numericId($variantNode['id'] ?? null, 'ProductVariant');
                $seenVariantIds[] = $shopifyVariantId;
                $variant = ProductVariant::query()->firstOrNew([
                    'product_id' => $product->getKey(),
                    'shopify_variant_id' => $shopifyVariantId,
                ]);

                $variant->exists ? $variantsUpdated++ : $variantsCreated++;
                $price = $variantNode['price'] ?? null;

                if (! is_scalar($price) || ! is_numeric((string) $price)) {
                    throw new ShopifyApiException('Shopify 商品变体缺少有效价格。');
                }

                $inventoryItem = $variantNode['inventoryItem'] ?? null;
                $variantAttributes = [
                    'title' => $this->requiredString($variantNode, 'title'),
                    'sku' => $this->nullableString($variantNode['sku'] ?? null),
                    'price' => (string) $price,
                    'inventory_item_id' => is_array($inventoryItem) && isset($inventoryItem['id'])
                        ? $this->numericId($inventoryItem['id'], 'InventoryItem')
                        : null,
                ];

                if (array_key_exists('compareAtPrice', $variantNode)) {
                    $variantAttributes['compare_at_price'] = $this->nullableMoney(
                        $variantNode['compareAtPrice'],
                        'compareAtPrice',
                    );
                }
                if (array_key_exists('availableForSale', $variantNode)) {
                    if (! is_bool($variantNode['availableForSale'])) {
                        throw new ShopifyApiException('Shopify 商品变体可售状态格式无效。');
                    }
                    $variantAttributes['available_for_sale'] = $variantNode['availableForSale'];
                }
                if (array_key_exists('selectedOptions', $variantNode)) {
                    $variantAttributes['selected_options'] = $this->selectedOptions($variantNode['selectedOptions']);
                }
                if (array_key_exists('media', $variantNode)) {
                    $variantAttributes = [
                        ...$variantAttributes,
                        ...$this->imageAttributes(data_get($variantNode, 'media.nodes.0'), 'image'),
                    ];
                }

                $variant->fill($variantAttributes)->save();
            }

            $removedVariants = $product->variants();

            if ($seenVariantIds !== []) {
                $removedVariants->whereNotIn('shopify_variant_id', $seenVariantIds);
            }

            $removedVariants->delete();

            return [
                'product' => $product,
                'created' => $created,
                'variants_created' => $variantsCreated,
                'variants_updated' => $variantsUpdated,
            ];
        });
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

        if (! is_string($value) || $value === '') {
            throw new ShopifyApiException("Shopify 商品数据缺少字段 [{$key}]。");
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<string> */
    private function tags(mixed $value): array
    {
        if (! is_array($value) || collect($value)->contains(fn ($tag) => ! is_string($tag))) {
            throw new ShopifyApiException('Shopify 商品标签格式无效。');
        }

        return collect($value)
            ->map(fn (string $tag): string => trim($tag))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<array{name: string, value: string}> */
    private function selectedOptions(mixed $value): array
    {
        if (! is_array($value)) {
            throw new ShopifyApiException('Shopify 商品变体选项格式无效。');
        }

        return collect($value)->map(function (mixed $option): array {
            if (! is_array($option)
                || ! is_string($option['name'] ?? null) || trim($option['name']) === ''
                || ! is_string($option['value'] ?? null) || trim($option['value']) === '') {
                throw new ShopifyApiException('Shopify 商品变体选项格式无效。');
            }

            return ['name' => trim($option['name']), 'value' => trim($option['value'])];
        })->values()->all();
    }

    private function nullableMoney(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_scalar($value) || ! is_numeric((string) $value)) {
            throw new ShopifyApiException("Shopify 商品变体价格 [{$field}] 格式无效。");
        }

        return (string) $value;
    }

    /** @return array<string, string|int|null> */
    private function imageAttributes(mixed $media, string $prefix): array
    {
        $image = is_array($media) ? ($media['image'] ?? null) : null;

        if ($media !== null && ! is_array($media)) {
            throw new ShopifyApiException('Shopify 商品图片格式无效。');
        }
        if ($image !== null && ! is_array($image)) {
            throw new ShopifyApiException('Shopify 商品图片格式无效。');
        }

        return [
            "{$prefix}_url" => is_array($image) ? $this->nullableString($image['url'] ?? null) : null,
            "{$prefix}_alt" => is_array($media) ? $this->nullableString($media['alt'] ?? null) : null,
            "{$prefix}_width" => is_array($image) ? $this->nullablePositiveInteger($image['width'] ?? null) : null,
            "{$prefix}_height" => is_array($image) ? $this->nullablePositiveInteger($image['height'] ?? null) : null,
        ];
    }

    private function nullablePositiveInteger(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) || $value <= 0) {
            throw new ShopifyApiException('Shopify 商品图片尺寸格式无效。');
        }

        return $value;
    }

    private function nullableDate(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new ShopifyApiException("Shopify 商品日期 [{$field}] 格式无效。");
        }

        try {
            return CarbonImmutable::parse($value)->utc()->toDateTimeString();
        } catch (\Throwable) {
            throw new ShopifyApiException("Shopify 商品日期 [{$field}] 格式无效。");
        }
    }
}
