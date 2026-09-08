<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateCoupon;
use App\Domain\ReferralAffiliate\Models\AffiliateLink;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use Illuminate\Support\Str;

class AffiliateAssetService
{
    /** @return array{link: AffiliateLink, coupon: AffiliateCoupon|null} */
    public function provisionDefaults(AffiliateProgramMembership $membership): array
    {
        $membership->loadMissing(['program', 'promoter', 'store']);
        app(AffiliateShopGuard::class)->store($membership->store);
        abort_unless((int) $membership->organization_id === (int) $membership->store->organization_id
            && (int) $membership->program->store_id === (int) $membership->store_id
            && (int) $membership->promoter->organization_id === (int) $membership->organization_id, 403);
        $link = AffiliateLink::withTrashed()->firstOrNew(['membership_id' => $membership->id]);
        if (! $link->exists) {
            $link->fill([
                'organization_id' => $membership->organization_id,
                'store_id' => $membership->store_id,
                'referral_code' => $this->uniqueCode(AffiliateLink::class, $membership->store_id, 'normalized_referral_code', 'REF'),
                'target_path' => '/',
                'utm' => ['source' => 'affiliate', 'medium' => 'referral'],
            ]);
            $link->normalized_referral_code = strtoupper($link->referral_code);
        }
        $link->status = 'active';
        $link->deleted_at = null;
        $link->save();

        $coupon = null;
        if ($membership->program->coupon_enabled) {
            $coupon = AffiliateCoupon::withTrashed()->firstOrNew(['membership_id' => $membership->id]);
            if (! $coupon->exists) {
                $coupon->fill([
                    'organization_id' => $membership->organization_id,
                    'store_id' => $membership->store_id,
                    'code' => $this->uniqueCode(AffiliateCoupon::class, $membership->store_id, 'normalized_code', 'DECO'),
                    'status' => 'provisioning',
                ]);
                $coupon->normalized_code = strtoupper($coupon->code);
            } elseif (in_array($coupon->status, ['disabled', 'disable_pending'], true)) {
                $coupon->status = $coupon->shopify_discount_id ? 'enable_pending' : 'provisioning';
            }
            $coupon->deleted_at = null;
            $coupon->save();
        }

        return ['link' => $link, 'coupon' => $coupon];
    }

    /** @param class-string<AffiliateLink|AffiliateCoupon> $model */
    private function uniqueCode(string $model, int $storeId, string $column, string $prefix): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = $prefix.'-'.strtoupper(Str::random(8));
            if (! $model::withTrashed()->where('store_id', $storeId)->where($column, $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('无法生成唯一推广代码。');
    }
}
