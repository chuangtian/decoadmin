<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateProgram;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Models\AffiliateStoreSetting;
use App\Jobs\PrepareAffiliateInvitation;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AffiliatePostPurchaseService
{
    public function enqueue(Store $store, array $order): void
    {
        app(AffiliateShopGuard::class)->store($store);
        if (! ($order['paid'] ?? false) || ($order['cancelled'] ?? false) || empty($order['customer_id']) || ! $this->enabled($store)) {
            return;
        }
        if (! $this->enabledAt($store, $order['ordered_at'])) {
            return;
        }
        AffiliateProgram::query()->forOrganization($store->organization_id)->forStore($store)->where('type', 'advocate')->where('status', 'active')->where('settings->auto_invite', true)->limit(100)->get()->each(function ($p) use ($store, $order) {
            if ($this->eligibleProgram($p, $order['ordered_at'])) {
                PrepareAffiliateInvitation::dispatch($store->organization_id, $store->id, $order['id'], $p->id)->afterCommit();
            }
        });
    }

    public function prepare(int $orgId, int $storeId, string $orderId, int $programId): void
    {
        $store = Store::query()->where('organization_id', $orgId)->findOrFail($storeId);
        app(AffiliateShopGuard::class)->store($store);
        if (! $this->enabled($store)) {
            return;
        }
        $program = AffiliateProgram::query()->forOrganization($orgId)->forStore($store)->where('type', 'advocate')->findOrFail($programId);
        if (! data_get($program->settings, 'auto_invite')) {
            return;
        }
        $contact = app(AffiliateInvitationOrderReader::class)->eligibleContact($store, $orderId);
        if (! $contact || ! $this->enabledAt($store, $contact['ordered_at']) || ! $this->eligibleProgram($program, $contact['ordered_at'])) {
            return;
        }
        $actor = User::query()->findOrFail($program->updated_by ?? $program->created_by);
        app(AffiliateShopGuard::class)->actor($store->organization, $store, $actor, 'affiliate.promoters.manage');
        DB::transaction(function () use ($store, $program, $contact, $actor, $orderId) {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            if (! $this->enabled($store) || ! $this->eligibleProgram($program->fresh(), $contact['ordered_at'])) {
                return;
            }
            $hash = hash_hmac('sha256', $store->organization_id.'|'.$contact['email'], (string) config('app.key'));
            if (AffiliateProgramMembership::query()->forStore($store)->where('program_id', $program->id)->whereHas('promoter', fn ($q) => $q->where('email_hash', $hash))->exists()) {
                return;
            }
            $promoter = app(AffiliateManagementService::class)->createPromoter($store->organization, $store, $actor, ['email' => $contact['email'], 'display_name' => '顾客推荐人', 'type' => 'advocate', 'program_public_id' => $program->public_id]);
            $member = $program->memberships()->where('promoter_id', $promoter->id)->firstOrFail();
            $member->forceFill(['application_encrypted' => ['source' => 'post_purchase', 'source_order_id' => $orderId]])->save();
            $url = app(AffiliateInvitationService::class)->issue($store->organization, $store, $actor, $member->public_id);
            app(AffiliateNotificationService::class)->intent($member, 'customer.invited', 'customer-invitation:'.$member->public_id, ['invitation_url' => $url]);
        });
    }

    private function enabledAt(Store $store, string $at): bool
    {
        $settings = AffiliateStoreSetting::query()->forOrganization($store->organization_id)->forStore($store)->first();

        return $settings && (bool) app(AffiliateAttributionEngine::class)->valueAt($settings, 'customer_referral_enabled', CarbonImmutable::parse($at));
    }

    private function enabled(Store $store): bool
    {
        return AffiliateStoreSetting::query()->forOrganization($store->organization_id)->forStore($store)->where('customer_referral_enabled', true)->exists();
    }

    private function eligibleProgram(AffiliateProgram $program, string $orderedAt): bool
    {
        $at = CarbonImmutable::parse($orderedAt);

        return $program->status->value === 'active' && app(AffiliateAttributionEngine::class)->valueAt($program, 'status', $at) === 'active' && data_get($program->settings, 'auto_invite') && $program->created_at->lte($at)
          && (! $program->starts_at || $program->starts_at->lte($at)) && (! $program->ends_at || $program->ends_at->gt($at))
          && data_get(app(AffiliateRuleHistory::class)->at($program,$at),'settings.auto_invite',false);
    }
}
