<?php

namespace DecoMarketing\Services;

use App\Models\Store;

class Coupons
{
    public const QUERY = 'query MarketingCoupon($code: String!) { codeDiscountNodeByCode(code: $code) { codeDiscount { ... on DiscountCodeBasic { title status startsAt endsAt summary } ... on DiscountCodeFreeShipping { title status startsAt endsAt summary } ... on DiscountCodeBxgy { title status startsAt endsAt summary } } } }';

    public function check(Store $store, string $code): array
    {
        app(Guard::class)->store($store);
        $base = ['code' => $code, 'checked_at' => now()->toIso8601String(), 'applicable_to_cart' => null];
        try {
            $response = app(Shopify::class)->query($store, self::QUERY, ['code' => $code]);
            if (! empty($response['errors'])) {
                return [...$base, 'status' => 'unavailable'];
            }
            $discount = data_get($response, 'data.codeDiscountNodeByCode.codeDiscount');
            if (! $discount) {
                return [...$base, 'status' => 'not_found'];
            }

            return [...$base, 'status' => $discount['status'], 'title' => $discount['title'], 'summary' => $discount['summary'], 'starts_at' => $discount['startsAt'], 'ends_at' => $discount['endsAt']];
        } catch (\Throwable) {
            return [...$base, 'status' => 'unavailable'];
        }
    }

    public function report(Store $store): array
    {
        $codes = app(Catalog::class)->settings($store)->coupons ?? [];

        return array_map(fn ($code) => $this->check($store, $code), array_slice(array_values(array_unique($codes)), 0, 10));
    }
}
