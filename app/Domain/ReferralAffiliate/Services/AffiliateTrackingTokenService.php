<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateClick;
use App\Domain\ReferralAffiliate\Models\AffiliateLink;
use App\Models\Store;
use Carbon\CarbonInterface;
use JsonException;

class AffiliateTrackingTokenService
{
    public function issue(AffiliateLink $link, AffiliateClick $click): string
    {
        app(AffiliateShopGuard::class)->store($link->membership->store);
        abort_unless((int) $click->link_id === (int) $link->id
            && (int) $click->store_id === (int) $link->store_id, 403);
        $payload = $this->encode(json_encode([
            'v' => 1,
            'membership' => $link->membership->public_id,
            'click' => $click->public_id,
            'exp' => $click->occurred_at->copy()->addDays($link->membership->program->attribution_window_days)->timestamp,
        ], JSON_THROW_ON_ERROR));

        return $payload.'.'.$this->encode(hash_hmac('sha256', 'affiliate-tracking-v1|'.$payload, (string) config('app.key'), true));
    }

    public function verify(Store $store, string $token, ?CarbonInterface $at = null): ?AffiliateClick
    {
        $at ??= now();
        app(AffiliateShopGuard::class)->store($store);
        if (strlen($token) > 2048 || count($parts = explode('.', $token)) !== 2) {
            return null;
        }
        [$payload, $signature] = $parts;
        $expected = $this->encode(hash_hmac('sha256', 'affiliate-tracking-v1|'.$payload, (string) config('app.key'), true));
        if (! hash_equals($expected, $signature)) {
            return null;
        }
        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }
        try {
            $claims = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($claims) || ($claims['v'] ?? null) !== 1
            || ! is_int($claims['exp'] ?? null) || $claims['exp'] <= $at->timestamp
            || ! is_string($claims['click'] ?? null) || ! is_string($claims['membership'] ?? null)) {
            return null;
        }

        return AffiliateClick::query()->forOrganization($store->organization_id)->forStore($store)
            ->where('public_id', $claims['click'])
            ->where('occurred_at', '<=', $at)
            ->whereHas('membership', fn ($query) => $query
                ->where('public_id', $claims['membership'])
                ->where('organization_id', $store->organization_id)->where('store_id', $store->id))
            ->first();
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
