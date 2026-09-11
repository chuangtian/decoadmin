<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Models\Organization;
use App\Models\Store;
use App\Models\User;

class AffiliateShopGuard
{
    // Explicit user-authorized pilot boundary; production remains disabled.
    public const TEST_SHOP = 'macfox-test-app.myshopify.com';

    public function store(Store $store): void
    {
        abort_unless(app()->environment(['local', 'testing', 'test', 'staging']), 403, '推荐与联盟尚未开放正式环境。');
        abort_unless(strtolower(trim((string) $store->shopify_domain)) === self::TEST_SHOP, 403, '推荐与联盟仅允许 macfox-test-app 测试店铺。');
        abort_unless($store->exists && $store->status === 'active'
            && $store->organization()->where('status', 'active')->exists(), 403);
    }

    public function actor(Organization $organization, Store $store, User $actor, string $permission): void
    {
        $this->store($store);
        abort_unless((int) $organization->id === (int) $store->organization_id
            && $actor->canAccessStore($store)
            && $actor->hasPermission($permission, $organization, $store), 403);
    }
}
