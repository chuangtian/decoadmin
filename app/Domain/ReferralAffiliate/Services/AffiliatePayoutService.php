<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateLedgerEntry;
use App\Domain\ReferralAffiliate\Models\AffiliatePayoutBatch;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AffiliatePayoutService
{
    public function create(Organization $org, Store $store, User $actor, string $currency, int $minimum, CarbonImmutable $cutoff): AffiliatePayoutBatch
    {
        app(AffiliateShopGuard::class)->actor($org, $store, $actor, 'affiliate.payouts.create');
        abort_unless($currency === $store->currency && $minimum > 0 && $cutoff->lte(now()), 422);

        return DB::transaction(function () use ($org, $store, $actor, $currency, $minimum, $cutoff) {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $entries = AffiliateLedgerEntry::query()->forOrganization($org)->forStore($store)->where('currency', $currency)->where('status', 'available')
                ->whereDoesntHave('conversion', fn ($q) => $q->whereIn('status', ['review', 'rejected'])
                    ->orWhereHas('risks', fn ($risk) => $risk->whereIn('status', ['open', 'reviewing', 'rejected'])))
                ->where(fn ($q) => $q->where('created_at', '<=', $cutoff)->orWhere('amount_minor', '<', 0))->orderBy('id')->limit(10001)->lockForUpdate()->get();
            abort_if($entries->count() > 10000, 422, '请缩小结算范围。');
            $members = AffiliateProgramMembership::query()->forOrganization($org)->forStore($store)->whereIn('id', $entries->pluck('membership_id'))->get()->keyBy('id');
            $selected = collect();
            foreach ($entries->groupBy(fn ($entry) => $members[$entry->membership_id]->promoter_id) as $promoterId => $group) {
                // Offset negative balances across all programs of this promoter in this store.
                if (! $this->hasUnresolvedBalance($store, (int) $promoterId, $group->pluck('id')->all()) && $group->sum('amount_minor') >= $minimum) {
                    $selected = $selected->concat($group);
                }
            }
            abort_if($selected->isEmpty(), 409, '没有达到付款门槛的可用余额。');
            $batch = AffiliatePayoutBatch::query()->create(['organization_id' => $org->id, 'store_id' => $store->id,
                'currency' => $currency, 'status' => 'draft', 'total_minor' => $selected->sum('amount_minor'), 'cutoff_at' => $cutoff, 'created_by' => $actor->id]);
            foreach ($selected->groupBy('membership_id') as $membershipId => $group) {
                $item = $batch->items()->create(['organization_id' => $org->id, 'store_id' => $store->id,
                    'membership_id' => $membershipId, 'amount_minor' => $group->sum('amount_minor')]);
                foreach ($group as $entry) {
                    $item->allocations()->create(['ledger_entry_id' => $entry->id, 'active_entry_id' => $entry->id]);
                    $entry->update(['status' => 'reserved']);
                }
            }
            $this->audit($batch, $actor, 'affiliate_payout_created');

            return $batch;
        }, 3);
    }

    public function transition(Organization $org, Store $store, User $actor, string $publicId, string $action, string $reference = '', ?string $proofPath = null): AffiliatePayoutBatch
    {
        app(AffiliateShopGuard::class)->actor($org, $store, $actor, $action === 'paid' ? 'affiliate.payouts.confirm' : 'affiliate.payouts.create');
        abort_unless(in_array($action, ['paid', 'cancelled', 'failed'], true), 422);

        return DB::transaction(function () use ($org, $store, $actor, $publicId, $action, $reference, $proofPath) {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $batch = AffiliatePayoutBatch::query()->where('organization_id', $org->id)->where('store_id', $store->id)->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            if ($batch->status === $action) {
                abort_if($action === 'paid' && ($batch->external_reference ?? '') !== $reference, 409);

                return $batch;
            }
            if ($action === 'paid' && trim($reference) === '' && ! $proofPath) {
                throw ValidationException::withMessages(['reference' => '请提供付款参考号或凭证。']);
            }
            abort_unless($batch->status === 'draft', 409, '此批次已处理，不能重复付款。');
            $items = $batch->items()->with('allocations', 'membership')->get();
            if ($action === 'paid') {
                foreach ($items->groupBy(fn ($item) => $item->membership->promoter_id) as $promoterId => $group) {
                    $ids = $group->flatMap(fn ($item) => $item->allocations->pluck('ledger_entry_id'))->all();
                    abort_if($this->hasUnresolvedBalance($store, (int) $promoterId, $ids), 409, '该推广者有新增退款、待审风险或未抵扣负余额，请取消批次后重新生成。');
                }
            }
            foreach ($items as $item) {
                foreach ($item->allocations as $allocation) {
                    $entry = AffiliateLedgerEntry::query()->forOrganization($org)->forStore($store)->whereKey($allocation->ledger_entry_id)->lockForUpdate()->firstOrFail();
                    abort_unless($entry->status === 'reserved' && (int) $entry->membership_id === (int) $item->membership_id, 409);
                    if ($action === 'paid' && $entry->conversion) {
                        abort_if(in_array($entry->conversion->status, ['review', 'rejected'], true)
                            || $entry->conversion->risks()->whereIn('status', ['open', 'reviewing', 'rejected'])->exists(), 409, '批次包含尚未通过风险审核的佣金。');
                    }
                    $entry->update(['status' => $action === 'paid' ? 'settled' : 'available']);
                    if ($action !== 'paid') {
                        $allocation->update(['active_entry_id' => null]);
                    }
                }
                if ($action === 'paid') {
                    AffiliateLedgerEntry::query()->create(['organization_id' => $org->id, 'store_id' => $store->id,
                        'membership_id' => $item->membership_id, 'idempotency_key' => 'payout:'.$batch->public_id.':'.$item->membership_id,
                        'type' => 'payout_settlement', 'status' => 'settled', 'currency' => $batch->currency,
                        'amount_minor' => -$item->amount_minor, 'created_by' => $actor->id, 'reason' => $reference,
                        'metadata' => ['batch_public_id' => $batch->public_id]]);
                }
                if ($action === 'paid') {
                    app(AffiliateNotificationService::class)->intent($item->membership, 'payout.paid', 'payout:'.$batch->public_id.':'.$item->membership_id);
                }
                $item->update(['status' => $action === 'paid' ? 'settled' : $action]);
            }
            $batch->update(['status' => $action, 'external_reference' => $reference ?: null,
                'proof_path' => $proofPath, 'paid_by' => $action === 'paid' ? $actor->id : null, 'paid_at' => $action === 'paid' ? now() : null]);
            $this->audit($batch, $actor, 'affiliate_payout_'.$action);

            return $batch;
        }, 3);
    }

    private function hasUnresolvedBalance(Store $store, int $promoterId, array $included): bool
    {
        $membershipIds = AffiliateProgramMembership::query()->forOrganization($store->organization_id)->forStore($store)->where('promoter_id', $promoterId)->select('id');

        return AffiliateLedgerEntry::query()->forOrganization($store->organization_id)->forStore($store)
            ->whereIn('membership_id', $membershipIds)->whereNotIn('id', $included)->whereNotIn('status', ['settled', 'superseded'])
            ->where(fn ($q) => $q->where('amount_minor', '<', 0)->orWhereHas('conversion', fn ($c) => $c->where('status', 'review')->orWhereHas('risks', fn ($r) => $r->whereIn('status', ['open', 'reviewing']))))->exists();
    }

    private function audit(AffiliatePayoutBatch $batch, User $actor, string $action): void
    {
        AuditLog::query()->create(['organization_id' => $batch->organization_id, 'store_id' => $batch->store_id,
            'user_id' => $actor->id, 'action' => $action, 'subject_type' => $batch::class, 'subject_id' => $batch->id,
            'metadata' => ['status' => $batch->status, 'currency' => $batch->currency, 'total_minor' => $batch->total_minor]]);
    }
}
