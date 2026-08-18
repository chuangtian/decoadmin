<?php

namespace App\Services\Shopify\Products;

use App\Exceptions\ShopifyApiException;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

class ShopifyProductDataService
{
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
            $product->fill([
                'organization_id' => $store->organization_id,
                'title' => $this->requiredString($productNode, 'title'),
                'handle' => $this->requiredString($productNode, 'handle'),
                'status' => strtolower($this->requiredString($productNode, 'status')),
                'vendor' => $this->nullableString($productNode['vendor'] ?? null),
                'product_type' => $this->nullableString($productNode['productType'] ?? null),
                'description' => $this->nullableString($productNode['description'] ?? null),
                'synced_at' => now(),
            ])->save();

            $variantsCreated = 0;
            $variantsUpdated = 0;

            foreach ($variantNodes as $variantNode) {
                $variant = ProductVariant::query()->firstOrNew([
                    'product_id' => $product->getKey(),
                    'shopify_variant_id' => $this->numericId($variantNode['id'] ?? null, 'ProductVariant'),
                ]);

                $variant->exists ? $variantsUpdated++ : $variantsCreated++;
                $price = $variantNode['price'] ?? null;

                if (! is_scalar($price) || ! is_numeric((string) $price)) {
                    throw new ShopifyApiException('Shopify 商品变体缺少有效价格。');
                }

                $inventoryItem = $variantNode['inventoryItem'] ?? null;
                $variant->fill([
                    'title' => $this->requiredString($variantNode, 'title'),
                    'sku' => $this->nullableString($variantNode['sku'] ?? null),
                    'price' => (string) $price,
                    'inventory_item_id' => is_array($inventoryItem) && isset($inventoryItem['id'])
                        ? $this->numericId($inventoryItem['id'], 'InventoryItem')
                        : null,
                ])->save();
            }

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
}
