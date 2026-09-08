<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateConversion;
use App\Domain\ReferralAffiliate\Models\AffiliateLedgerEntry;
use App\Domain\ReferralAffiliate\Models\AffiliateNotificationIntent;
use App\Domain\ReferralAffiliate\Models\AffiliatePayoutItem;
use App\Domain\ReferralAffiliate\Models\AffiliatePortalToken;
use App\Domain\ReferralAffiliate\Models\AffiliateProgram;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Models\AffiliatePromoter;
use App\Domain\ReferralAffiliate\Models\AffiliateReward;
use App\Domain\ReferralAffiliate\Models\AffiliateStoreSetting;
use App\Domain\ReferralAffiliate\Support\Money;
use App\Jobs\SendAffiliateNotification;
use App\Models\AuditLog;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class AffiliatePortalService
{
    public function updateProfile(Store $store, int $memberId, array $values): void
    {
        $member = $this->member($store, $memberId);
        DB::transaction(function () use ($store, $member, $values) {
            $member->promoter->forceFill(['profile_encrypted' => $values])->save();
            AuditLog::query()->create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'action' => 'affiliate_portal_profile_updated',
                'subject_type' => $member->promoter::class, 'subject_id' => $member->promoter->id, 'metadata' => ['fields' => array_keys($values)]]);
        });
    }

    public function dashboard(Store $store, int $memberId): array
    {
        $member = $this->member($store, $memberId);
        $entries = AffiliateLedgerEntry::query()->forOrganization($store->organization_id)->forStore($store)->where('membership_id', $member->id);
        $balances = (clone $entries)->selectRaw('status, SUM(amount_minor) as total')->groupBy('status')->get()->mapWithKeys(fn ($row) => [$row->status => Money::decimal((int) $row->total, $store->currency)])->all();
        $conversions = AffiliateConversion::query()->forOrganization($store->organization_id)->forStore($store)->where('membership_id', $member->id)->latest('id')->paginate(20, ['public_id', 'ordered_at', 'status', 'commission_minor', 'reversed_minor', 'currency']);

        return ['store' => $store, 'member' => $member, 'balances' => $balances, 'conversions' => $conversions,
            'entries' => (clone $entries)->latest('id')->limit(50)->get(['public_id', 'type', 'status', 'currency', 'amount_minor', 'created_at']),
            'payouts' => AffiliatePayoutItem::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)->where('membership_id', $member->id)->latest('id')->limit(50)->get(['amount_minor', 'status', 'created_at']),
            'clicks' => app(AffiliateTrackingStatistics::class)->count($store, $member->id),
            'rewards' => AffiliateReward::query()->forOrganization($store->organization_id)->forStore($store)->where('membership_id', $member->id)->latest('id')->limit(100)->get()->map(fn ($r) => ['code' => in_array($r->status, ['issued', 'redeemed', 'expired'], true) ? $r->code : null, 'status' => $r->status, 'expires_at' => $r->expires_at?->format('Y-m-d'), 'threshold' => $r->threshold]),
            'assets' => DB::table('affiliate_assets')->where('organization_id', $store->organization_id)->where('store_id', $store->id)->latest('id')->limit(100)->get(['public_id', 'title'])];
    }

    public function store(): Store
    {
        $store = Store::query()->where('shopify_domain', AffiliateShopGuard::TEST_SHOP)->firstOrFail();
        app(AffiliateShopGuard::class)->store($store);

        return $store;
    }

    public function apply(Store $store, array $values): void
    {
        app(AffiliateShopGuard::class)->store($store);
        DB::transaction(function () use ($store, $values) {
            $program = AffiliateProgram::query()->forOrganization($store->organization_id)->forStore($store)->where('public_id', $values['program'])->where('status', 'active')->firstOrFail();
            abort_unless(AffiliateStoreSetting::query()->forStore($store)->where($program->type->value === 'advocate' ? 'customer_referral_enabled' : 'affiliate_enabled', true)->exists(), 409, '此店铺暂未开放申请。');
            $email = mb_strtolower(trim($values['email']));
            $hash = hash_hmac('sha256', $store->organization_id.'|'.$email, (string) config('app.key'));
            $promoter = AffiliatePromoter::query()->firstOrCreate(['organization_id' => $store->organization_id, 'email_hash' => $hash], [
                'email_encrypted' => $email, 'display_name' => $values['name'], 'type' => $program->type->value, 'status' => 'active',
            ]);
            // Public re-application never modifies an existing identity, profile or membership.
            $member = AffiliateProgramMembership::query()->firstOrCreate(['program_id' => $program->id, 'promoter_id' => $promoter->id], [
                'organization_id' => $store->organization_id, 'store_id' => $store->id, 'status' => 'pending',
            ]);
            if ($member->wasRecentlyCreated) {
                $member->forceFill(['application_encrypted' => ['country' => $values['country'] ?? '', 'website' => $values['website'] ?? '',
                    'method' => $values['method'] ?? '', 'notes' => $values['notes'] ?? '', 'terms_accepted' => true, 'terms_accepted_at' => now()->toIso8601String()]])->save();
                $this->audit($store, $member, 'affiliate_application_received');
                app(AffiliateNotificationService::class)->intent($member, 'promoter.application_received', 'application:'.$member->public_id);
            }
        });
    }

    public function requestLogin(Store $store, string $email, string $programPublicId): void
    {
        app(AffiliateShopGuard::class)->store($store);
        $hash = hash_hmac('sha256', $store->organization_id.'|'.mb_strtolower(trim($email)), (string) config('app.key'));
        $key = 'affiliate-login:'.$store->id.':'.$hash;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return;
        }
        RateLimiter::hit($key, 900);
        $member = AffiliateProgramMembership::query()->forOrganization($store->organization_id)->forStore($store)->where('status', 'approved')
            ->whereHas('program', fn ($q) => $q->where('public_id', $programPublicId))
            ->whereHas('promoter', fn ($q) => $q->where('email_hash', $hash)->where('status', 'active'))->with('promoter', 'program')->first();
        if (! $member) {
            return;
        }
        $token = $this->issue($store, $member);
        abort_if(config('mail.default') === 'log', 503, '请先配置安全的邮件发送服务。');
        $intent = AffiliateNotificationIntent::query()->create([
            'organization_id' => $store->organization_id, 'store_id' => $store->id, 'membership_id' => $member->id,
            'event_key' => 'portal.login', 'dedupe_key' => 'login:'.hash('sha256', $token), 'template_version' => 1,
            'recipient_hash' => $member->promoter->email_hash, 'status' => 'queued',
            'message_encrypted' => ['to' => $member->promoter->email_encrypted, 'subject' => '推广者门户登录',
                'body' => '请在 15 分钟内使用此一次性链接登录：'.url('/referral-portal/login').'#token='.$token],
        ]);
        SendAffiliateNotification::dispatch($intent->id)->afterCommit();
    }

    public function issue(Store $store, AffiliateProgramMembership $member): string
    {
        app(AffiliateShopGuard::class)->store($store);
        abort_unless((int) $member->organization_id === (int) $store->organization_id && (int) $member->store_id === (int) $store->id && $member->status->value === 'approved', 403);
        $raw = bin2hex(random_bytes(32));
        AffiliatePortalToken::query()->create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'membership_id' => $member->id,
            'token_hash' => hash('sha256', $raw), 'expires_at' => now()->addMinutes(15)]);

        return $raw;
    }

    public function consume(Store $store, string $raw): AffiliateProgramMembership
    {
        app(AffiliateShopGuard::class)->store($store);
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $raw) === 1, 401);

        return DB::transaction(function () use ($store, $raw) {
            $token = AffiliatePortalToken::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)
                ->where('token_hash', hash('sha256', $raw))->lockForUpdate()->first();
            abort_unless($token && ! $token->used_at && $token->expires_at->isFuture(), 401, '登录链接已失效，请重新申请。');
            $member = $this->member($store, $token->membership_id);
            $token->update(['used_at' => now()]);
            $this->audit($store, $member, 'affiliate_portal_login');

            return $member;
        });
    }

    public function member(Store $store, int $id): AffiliateProgramMembership
    {
        app(AffiliateShopGuard::class)->store($store);

        return AffiliateProgramMembership::query()->forOrganization($store->organization_id)->forStore($store)->whereKey($id)
            ->where('status', 'approved')->whereHas('promoter', fn ($q) => $q->where('status', 'active'))->with('promoter', 'program', 'link', 'coupon')->firstOrFail();
    }

    private function audit(Store $store, AffiliateProgramMembership $member, string $action): void
    {
        AuditLog::query()->create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'action' => $action,
            'subject_type' => AffiliateProgramMembership::class, 'subject_id' => $member->id, 'metadata' => ['membership_public_id' => $member->public_id]]);
    }
}
