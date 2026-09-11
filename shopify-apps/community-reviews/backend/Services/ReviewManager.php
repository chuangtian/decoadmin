<?php

namespace CommunityReviews\Services;

use App\Models\AuditLog;
use App\Models\ModelAssetFolder;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use CommunityReviews\Models\ModelLink;
use CommunityReviews\Models\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewManager
{
    public function authorize(User $user, Store $store, bool $write = false): void
    {
        abort_unless($store->status === 'active' && $store->organization?->status === 'active', 404);
        abort_unless($user->canAccessStore($store)
            && $user->hasPermission('products.view', $store->organization, $store)
            && $user->hasPermission('reports.view', $store->organization, $store)
            && (! $write || $user->hasPermission('products.update', $store->organization, $store)), 403);
    }

    public function save(Store $store, User $user, array $values): void
    {
        $this->authorize($user, $store, true);
        DB::transaction(function () use ($store, $user, $values): void {
            foreach ($values['models'] as $index => $model) {
                $folder = ModelAssetFolder::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)
                    ->where('uuid', $model['folder_id'])->lockForUpdate()->first();
                $product = Product::query()->forOrganization($store->organization_id)->forStore($store->id)->find($model['product_id']);
                if (! $folder || ! $product) {
                    throw ValidationException::withMessages(["models.{$index}.product_id" => '文件夹和商品必须属于当前店铺。']);
                }
                ModelLink::query()->updateOrCreate(['folder_id' => $folder->id], [
                    'organization_id' => $store->organization_id, 'store_id' => $store->id, 'product_id' => $product->id,
                    'label' => trim($model['label']), 'series' => trim($model['series'] ?? ''),
                    'aliases' => array_values(array_unique(array_filter(array_map('trim', $model['aliases'] ?? [])))),
                    'enabled' => $model['enabled'],
                ]);
            }
            $kept = ModelAssetFolder::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)
                ->whereIn('uuid', array_column($values['models'], 'folder_id'))->pluck('id');
            ModelLink::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)
                ->whereNotIn('folder_id', $kept)->delete();
            Settings::query()->updateOrCreate(['store_id' => $store->id], [
                'organization_id' => $store->organization_id, 'enabled' => $values['enabled'],
                'heading' => trim($values['heading']), 'read_more_url' => $values['read_more_url'] ?: null,
                'card_count' => $values['card_count'],
            ]);
            AuditLog::query()->create([
                'organization_id' => $store->organization_id, 'store_id' => $store->id, 'user_id' => $user->id,
                'action' => 'community_reviews_settings_saved', 'subject_type' => Store::class, 'subject_id' => $store->id,
                'metadata' => ['enabled' => $values['enabled'], 'model_count' => count($values['models'])],
            ]);
        });
    }
}
