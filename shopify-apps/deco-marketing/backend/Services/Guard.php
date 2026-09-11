<?php

namespace DecoMarketing\Services;

use App\Models\Store;
use App\Models\User;

class Guard
{
    public const SHOP = 'macfox-test-app.myshopify.com';
    public const RECONCILIATION_SHOP = 'macfoxebike.myshopify.com';

    public function domains(): array
    {
        return config('marketing.reconciliation') ? [self::SHOP, self::RECONCILIATION_SHOP] : [self::SHOP];
    }

    public function previewOnly(Store $store): bool
    {
        return config('marketing.reconciliation') || $store->shopify_domain === self::RECONCILIATION_SHOP;
    }

    public function store(Store $store): void
    {
        abort_unless(app()->environment(['local', 'testing', 'test', 'staging']) && config('marketing.environment') !== 'production', 403, '营销模块仅开放测试环境。');
        abort_unless($store->exists && in_array($store->shopify_domain, $this->domains(), true) && $store->status === 'active'
            && Store::whereKey($store->id)->where('organization_id', $store->organization_id)->where('shopify_domain', $store->shopify_domain)->where('status', 'active')->exists()
            && $store->organization()->where('status', 'active')->exists(), 403, '仅允许 macfox-test-app 测试店铺。');
    }

    public function actor(Store $store, User $actor, bool $write = false): void
    {
        $this->store($store);
        abort_unless($actor->status === 'active' && $actor->canAccessStore($store)
            && $actor->hasPermission($write ? 'marketing.manage' : 'marketing.view', $store->organization, $store), 403);
    }

    public function recipient(string $email): bool
    {
        return in_array(strtolower(trim($email)), array_map('strtolower', config('marketing.recipients', [])), true);
    }
}
