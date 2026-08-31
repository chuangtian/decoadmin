<?php

namespace App\Services\Personalization;

use App\Exceptions\PersonalizationException;

class PersonalizationShopGuard
{
    public function normalize(string $shop): string
    {
        return strtolower(trim($shop));
    }

    public function isValid(string $shop): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $this->normalize($shop)) === 1;
    }

    public function assertAllowed(string $shop): string
    {
        $shop = $this->normalize($shop);
        if (! $this->isValid($shop)) {
            throw new PersonalizationException('STORE_NOT_CONNECTED', '未找到对应的 DecoAdmin 店铺。', 404);
        }

        $denied = collect(config('personalization.denied_shop_domains', []))
            ->filter(fn (mixed $value): bool => is_string($value))
            ->map(fn (string $value): string => $this->normalize($value))
            ->contains(fn (string $value): bool => $value !== '' && hash_equals($value, $shop));
        if ($denied) {
            throw new PersonalizationException('SHOP_WRITE_DENIED', '该店铺禁止用于个性化推荐 App 操作。', 403);
        }

        return $shop;
    }
}
