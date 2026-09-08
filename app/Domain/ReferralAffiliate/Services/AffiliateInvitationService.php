<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Models\AffiliateStoreSetting;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AffiliateInvitationService
{
    public function issue(Organization $org, Store $store, User $actor, string $membership): string
    {
        app(AffiliateShopGuard::class)->actor($org, $store, $actor, 'affiliate.promoters.manage');

        return DB::transaction(function () use ($org, $store, $actor, $membership) {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $member = AffiliateProgramMembership::query()->forOrganization($org)->forStore($store)->where('public_id', $membership)->with('program', 'promoter')->firstOrFail();
            $this->eligible($store, $member);
            DB::table('affiliate_invitations')->where('store_id', $store->id)->where('membership_id', $member->id)->whereNull('accepted_at')->update(['expires_at' => now()]);
            $token = bin2hex(random_bytes(32));
            DB::table('affiliate_invitations')->insert(['organization_id' => $org->id, 'store_id' => $store->id, 'membership_id' => $member->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7), 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            AuditLog::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'user_id' => $actor->id, 'action' => 'affiliate_invitation_created', 'subject_type' => $member::class, 'subject_id' => $member->id]);

            return url('/referral-portal/invitation').'#token='.$token;
        });
    }

    public function accept(Store $store, string $token, array $values): void
    {
        app(AffiliateShopGuard::class)->store($store);
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token) && filter_var($values['terms'] ?? false, FILTER_VALIDATE_BOOL), 422);
        DB::transaction(function () use ($store, $token, $values) {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $invite = DB::table('affiliate_invitations')->where('organization_id', $store->organization_id)->where('store_id', $store->id)->where('token_hash', hash('sha256', $token))->whereNull('accepted_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            abort_unless($invite, 410, '邀请已使用或过期，请联系店铺重新邀请。');
            $member = AffiliateProgramMembership::query()->forOrganization($store->organization_id)->forStore($store)->with('program', 'promoter')->findOrFail($invite->membership_id);
            $this->eligible($store, $member);
            $member->forceFill(['application_encrypted' => array_merge($member->application_encrypted ?? [], ['invitation_accepted_at' => now()->toIso8601String(), 'terms_accepted' => true, 'website' => $values['website'] ?? '', 'notes' => $values['notes'] ?? ''])])->save();
            DB::table('affiliate_invitations')->where('id', $invite->id)->update(['accepted_at' => now(), 'updated_at' => now()]);
            AuditLog::query()->create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'action' => 'affiliate_invitation_accepted', 'subject_type' => $member::class, 'subject_id' => $member->id]);
        });
    }

    private function eligible(Store $store, AffiliateProgramMembership $member): void
    {
        abort_unless(in_array($member->status->value, ['pending', 'waitlisted'], true) && $member->program->status->value === 'active' && $member->promoter->status === 'active', 409, '此成员或计划当前不接受邀请。');
        abort_unless(AffiliateStoreSetting::query()->forOrganization($store->organization_id)->forStore($store)->where($member->program->type->value === 'advocate' ? 'customer_referral_enabled' : 'affiliate_enabled', true)->exists(), 409, '请先启用相应店铺功能。');
    }
}
