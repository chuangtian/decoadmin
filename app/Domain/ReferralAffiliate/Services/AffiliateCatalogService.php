<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AffiliateCatalogService
{
    public function scopedQuery(string $scope, Organization $org, Store $store): Builder
    {
        if ($scope === 'variant') {
            return ProductVariant::query()->whereHas('product', fn ($q) => $q->where('organization_id', $org->id)->where('store_id', $store->id));
        }
        $model = match ($scope) {
            'product' => Product::class, 'collection' => ProductCollection::class, default => abort(422)
        };

        return $model::query()->where('organization_id', $org->id)->where('store_id', $store->id);
    }

    public function search(Organization $org, Store $store, User $actor, string $scope, string $term): array
    {
        app(AffiliateShopGuard::class)->actor($org, $store, $actor, 'affiliate.programs.manage');
        [$model,$field,$type] = match ($scope) {
            'product' => [Product::class, 'shopify_product_id', 'Product'],'variant' => [ProductVariant::class, 'shopify_variant_id', 'ProductVariant'],'collection' => [ProductCollection::class, 'shopify_collection_id', 'Collection'],default => abort(422)
        };

        return $this->scopedQuery($scope, $org, $store)->where('title', 'like', '%'.mb_substr($term, 0, 100).'%')
            ->orderBy('title')->limit(50)->get()->map(fn ($m) => ['id' => 'gid://shopify/'.$type.'/'.$m->getAttribute($field), 'name' => $m->title])->all();
    }
}
