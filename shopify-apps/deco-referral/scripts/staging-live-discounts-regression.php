<?php

/** Actual Shopify discount lifecycle on the authorized test shop. No payments or external mail. */

use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Models\AffiliateStoreSetting;
use App\Domain\ReferralAffiliate\Services\AffiliateAppTokenService;
use App\Domain\ReferralAffiliate\Services\AffiliateCouponSyncService;
use App\Domain\ReferralAffiliate\Services\AffiliateManagementService;
use App\Domain\ReferralAffiliate\Services\AffiliateShopGuard;
use App\Models\Store;
use App\Models\User;
use App\Services\Shopify\ShopifyGraphQLClient;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('staging') || rtrim(config('app.url'), '/') !== 'https://testadmin.decomkt.com') {
    throw new RuntimeException('Staging only');
}
$store = Store::query()->where('shopify_domain', AffiliateShopGuard::TEST_SHOP)->firstOrFail();
$org = $store->organization;
$actor = User::query()->findOrFail(AffiliateProgramMembership::query()->forStore($store)->whereNotNull('approved_by')->oldest()->firstOrFail()->approved_by);
app(AffiliateShopGuard::class)->actor($org, $store, $actor, 'affiliate.programs.manage');
Queue::fake();
$management = app(AffiliateManagementService::class);
$sync = app(AffiliateCouponSyncService::class);
$before = AffiliateStoreSetting::query()->forStore($store)->firstOrFail()->only(['affiliate_enabled', 'customer_referral_enabled']);
$fixtures = $results = [];
$assert = function (bool $ok, string $label) {
    if (! $ok) {
        throw new RuntimeException('Live regression failed: '.$label);
    }
};
$read = function ($coupon) use ($store) {
    $query = (new ReflectionClass(AffiliateCouponSyncService::class))->getConstant('FIND');

    return data_get(app(ShopifyGraphQLClient::class)->queryWithAccessToken($store->shopify_domain, app(AffiliateAppTokenService::class)->accessTokenFor($store), $query, ['code' => $coupon->code], 20, '2026-07'), 'data.codeDiscountNodeByCode');
};
try {
    $management->updateSettings($org, $store, $actor, array_replace($before, ['affiliate_enabled' => true]));
    foreach (['percentage', 'fixed', 'free_shipping'] as $type) {
        $name = 'TEST live lifecycle '.$type.' '.strtolower(substr((string) Str::ulid(), -8));
        $values = ['name' => $name, 'type' => 'affiliate', 'attribution_model' => 'coupon_wins', 'attribution_window_days' => 30, 'hold_days' => 30, 'commission_type' => 'percentage', 'rate_basis_points' => 1200, 'coupon_enabled' => true, 'customer_discount_type' => $type, 'customer_discount_rate_basis_points' => 1000, 'customer_discount_amount_minor' => 1000];
        $program = $management->createProgram($org, $store, $actor, $values);
        $fixtures[] = $program;
        $management->transitionProgram($org, $store, $actor, $program->public_id, 'activate');
        $promoter = $management->createPromoter($org, $store, $actor, ['display_name' => $name, 'email' => 'live-'.strtolower((string) Str::ulid()).'@example.invalid', 'type' => 'affiliate', 'program_public_id' => $program->public_id]);
        $member = $program->memberships()->where('promoter_id', $promoter->id)->firstOrFail();
        $management->transitionMembership($org, $store, $actor, $member->public_id, 'approve');
        $coupon = $member->fresh()->coupon;
        $coupon = $sync->sync($org->id, $store->id, $coupon->id);
        $node = $read($coupon);
        $assert(data_get($node, 'codeDiscount.status') === 'ACTIVE', $type.' created active');
        $id = $node['id'];
        $sync->sync($org->id, $store->id, $coupon->id);
        $assert($coupon->fresh()->shopify_discount_id === $id, $type.' repeat retains same discount');
        $management->updateProgram($org, $store, $actor, $program->public_id, array_replace($values, ['starts_at' => now()->addDay()->toIso8601String(), 'ends_at' => now()->addDays(3)->toIso8601String()]));
        $sync->sync($org->id, $store->id, $coupon->id);
        $assert(data_get($read($coupon), 'codeDiscount.status') === 'SCHEDULED', $type.' scheduled');
        $management->updateProgram($org, $store, $actor, $program->public_id, array_replace($values, ['starts_at' => null, 'ends_at' => null]));
        $sync->sync($org->id, $store->id, $coupon->id);
        $assert(data_get($read($coupon), 'codeDiscount.status') === 'ACTIVE', $type.' cleared schedule');
        $management->transitionProgram($org, $store, $actor, $program->public_id, 'pause');
        $sync->sync($org->id, $store->id, $coupon->id);
        $assert(data_get($read($coupon), 'codeDiscount.status') === 'EXPIRED', $type.' paused and disabled');
        $results[$type] = ['created' => true, 'idempotent' => true, 'scheduled' => true, 'schedule_cleared' => true, 'disabled' => true];
    }
} finally {
    foreach ($fixtures as $program) {
        if ($program->fresh()->status->value === 'active') {
            $management->transitionProgram($org, $store, $actor, $program->public_id, 'pause');
        }
        foreach ($program->memberships()->with('coupon')->get() as $member) {
            if ($member->coupon && $member->coupon->status !== 'disabled') {
                $sync->sync($org->id, $store->id, $member->coupon->id);
            }
        }
    }
    $management->updateSettings($org, $store, $actor, $before);
    foreach ($fixtures as $program) {
        foreach ($program->memberships()->with('coupon')->get() as $member) {
            if ($member->coupon) {
                $sync->sync($org->id, $store->id, $member->coupon->id);
            }
        }
    }
}
echo json_encode(['shop' => $store->shopify_domain, 'actual_shopify_discount_lifecycles' => $results, 'settings_restored' => true, 'fixtures_paused' => true, 'real_transfer' => false, 'external_mail_sent' => false], JSON_PRETTY_PRINT).PHP_EOL;
