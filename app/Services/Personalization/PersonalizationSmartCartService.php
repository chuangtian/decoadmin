<?php

namespace App\Services\Personalization;

use App\Enums\PersonalizationSmartCartCompatibilityStatus;
use App\Exceptions\PersonalizationException;
use App\Models\PersonalizationSmartCartSetting;
use App\Models\Store;

class PersonalizationSmartCartService
{
    public function __construct(
        private PersonalizationRecommendationService $recommendations,
        private PersonalizationShopGuard $shopGuard,
    ) {}

    /** @param array<string, mixed> $context @return array<string, mixed> */
    public function storefront(Store $store, array $context): array
    {
        $this->shopGuard->assertAllowed((string) $store->shopify_domain);
        $setting = PersonalizationSmartCartSetting::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->with('strategy')
            ->first();
        if (! $setting || ! $setting->enabled) {
            return ['enabled' => false, 'fallback_mode' => 'shopify_default'];
        }
        if ($setting->compatibility_status !== PersonalizationSmartCartCompatibilityStatus::Compatible
            || ! $setting->preview_confirmed_at
            || ! $setting->strategy
            || ! $setting->strategy->enabled) {
            throw new PersonalizationException(
                'SMART_CART_SAFETY_GATE_FAILED',
                'Smart Cart 安全门禁未通过。',
                409,
            );
        }

        return [
            'enabled' => true,
            'fallback_mode' => 'shopify_default',
            'heading' => trim((string) data_get($setting->settings, 'heading', '购物车推荐')),
            'recommendations' => $this->recommendations->recommend($store, $setting->strategy, $context),
        ];
    }
}
