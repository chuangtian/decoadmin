<?php

namespace App\Services\Shopify\Inventory;

use App\Exceptions\ShopifyApiException;
use App\Models\InventoryItem;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

class ShopifyInventoryDataService
{
    /**
     * Persist one complete Shopify inventory item snapshot.
     *
     * This entry point is intentionally reusable by a future
     * inventory_levels/update webhook handler.
     *
     * @param  array<string, mixed>  $inventoryItemNode
     * @param  list<array<string, mixed>>  $levelNodes
     * @return array{inventory_item: InventoryItem, created: bool, levels_created: int, levels_updated: int, locations_created: int, locations_updated: int}
     */
    public function upsert(Store $store, array $inventoryItemNode, array $levelNodes): array
    {
        return DB::transaction(function () use ($store, $inventoryItemNode, $levelNodes): array {
            $shopifyInventoryItemId = $this->numericId(
                $inventoryItemNode['id'] ?? null,
                'InventoryItem',
            );
            $shopifyVariantId = $this->nullableNumericId(
                data_get($inventoryItemNode, 'variants.nodes.0.id'),
                'ProductVariant',
            );
            $variant = $shopifyVariantId
                ? ProductVariant::query()
                    ->where('shopify_variant_id', $shopifyVariantId)
                    ->whereHas('product', fn ($query) => $query->where('store_id', $store->getKey()))
                    ->first()
                : null;
            $inventoryItem = InventoryItem::query()->firstOrNew([
                'store_id' => $store->getKey(),
                'shopify_inventory_item_id' => $shopifyInventoryItemId,
            ]);
            $created = ! $inventoryItem->exists;
            $tracked = $inventoryItemNode['tracked'] ?? null;

            if (! is_bool($tracked)) {
                throw new ShopifyApiException('Shopify 库存项目的跟踪状态格式无效。');
            }

            $inventoryItem->fill([
                'organization_id' => $store->organization_id,
                'variant_id' => $variant?->getKey(),
                'shopify_variant_id' => $shopifyVariantId,
                'sku' => $this->nullableString($inventoryItemNode['sku'] ?? null),
                'tracked' => $tracked,
                'synced_at' => now(),
            ])->save();

            $levelsCreated = 0;
            $levelsUpdated = 0;
            $locationsCreated = 0;
            $locationsUpdated = 0;

            foreach ($levelNodes as $levelNode) {
                $locationNode = $levelNode['location'] ?? null;

                if (! is_array($locationNode)) {
                    throw new ShopifyApiException('Shopify 库存级别缺少地点信息。');
                }

                $shopifyLocationId = $this->numericId($locationNode['id'] ?? null, 'Location');
                $location = Location::query()->firstOrNew([
                    'store_id' => $store->getKey(),
                    'shopify_location_id' => $shopifyLocationId,
                ]);
                $location->exists ? $locationsUpdated++ : $locationsCreated++;
                $active = $locationNode['isActive'] ?? ($location->exists ? $location->active : true);

                if (! is_bool($active)) {
                    throw new ShopifyApiException('Shopify 地点的启用状态格式无效。');
                }

                $location->fill([
                    'organization_id' => $store->organization_id,
                    'name' => $this->nullableString($locationNode['name'] ?? null)
                        ?? ($location->exists ? $location->name : "Shopify Location {$shopifyLocationId}"),
                    'address' => $this->address($locationNode['address'] ?? $location->address),
                    'active' => $active,
                    'synced_at' => now(),
                ])->save();

                $quantity = $this->availableQuantity($levelNode);
                $level = InventoryLevel::query()->firstOrNew([
                    'inventory_item_id' => $inventoryItem->getKey(),
                    'location_id' => $location->getKey(),
                ]);
                $level->exists ? $levelsUpdated++ : $levelsCreated++;
                $level->fill([
                    'shopify_location_id' => $shopifyLocationId,
                    'available' => $quantity['quantity'],
                    'updated_at_shopify' => $this->nullableDate($quantity['updatedAt'] ?? null),
                    'synced_at' => now(),
                ])->save();
            }

            return [
                'inventory_item' => $inventoryItem,
                'created' => $created,
                'levels_created' => $levelsCreated,
                'levels_updated' => $levelsUpdated,
                'locations_created' => $locationsCreated,
                'locations_updated' => $locationsUpdated,
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

    private function nullableNumericId(mixed $gid, string $resource): ?string
    {
        return $gid === null ? null : $this->numericId($gid, $resource);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, string|null>|null */
    private function address(mixed $address): ?array
    {
        if ($address === null) {
            return null;
        }

        if (! is_array($address)) {
            throw new ShopifyApiException('Shopify 地点地址格式无效。');
        }

        return [
            'address1' => $this->nullableString($address['address1'] ?? null),
            'address2' => $this->nullableString($address['address2'] ?? null),
            'city' => $this->nullableString($address['city'] ?? null),
            'province' => $this->nullableString($address['province'] ?? null),
            'province_code' => $this->nullableString($address['provinceCode'] ?? null),
            'country' => $this->nullableString($address['country'] ?? null),
            'country_code' => $this->nullableString($address['countryCode'] ?? null),
            'zip' => $this->nullableString($address['zip'] ?? null),
            'phone' => $this->nullableString($address['phone'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $levelNode
     * @return array{name: string, quantity: int, updatedAt?: mixed}
     */
    private function availableQuantity(array $levelNode): array
    {
        $quantities = $levelNode['quantities'] ?? null;

        if (! is_array($quantities)) {
            throw new ShopifyApiException('Shopify 库存级别数量格式无效。');
        }

        $available = collect($quantities)->first(
            fn ($quantity) => is_array($quantity) && ($quantity['name'] ?? null) === 'available',
        );

        if (! is_array($available) || ! is_int($available['quantity'] ?? null)) {
            throw new ShopifyApiException('Shopify 库存级别缺少可用数量。');
        }

        return $available;
    }

    private function nullableDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || strtotime($value) === false) {
            throw new ShopifyApiException('Shopify 库存数量更新时间格式无效。');
        }

        return $value;
    }
}
