<?php

namespace App\Console\Commands;

use App\Domain\ReferralAffiliate\Models\AffiliateCoupon;
use App\Domain\ReferralAffiliate\Services\AffiliateLedgerService;
use App\Domain\ReferralAffiliate\Services\AffiliateReconciliationService;
use App\Domain\ReferralAffiliate\Services\AffiliateShopGuard;
use App\Jobs\ProcessWebhookEventJob;
use App\Jobs\SyncAffiliateCoupon;
use App\Models\Store;
use App\Models\WebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class AffiliateMaintenance extends Command
{
    protected $signature = 'affiliate:maintenance {--loop : Run the dedicated test maintenance process}';

    protected $description = 'Release eligible commissions and reconcile only the authorized Referral pilot';

    public function handle(): int
    {
        do {
            $store = Store::query()->where('shopify_domain', AffiliateShopGuard::TEST_SHOP)->first();
            if ($store) {
                app(AffiliateShopGuard::class)->store($store);
                Cache::lock('affiliate-maintenance:'.config('referral.environment').':'.$store->id, 55)->get(function () use ($store) {
                    app(AffiliateLedgerService::class)->release($store);
                    AffiliateCoupon::query()->forOrganization($store->organization_id)->forStore($store)
                        ->whereIn('status', ['provisioning', 'sync_pending', 'enable_pending', 'disable_pending'])->where('updated_at', '<', now()->subMinutes(5))
                        ->orderBy('id')->limit(100)->get()->each(function ($coupon) {
                            if (Cache::add('affiliate-retry-coupon:'.$coupon->id, true, 300)) {
                                SyncAffiliateCoupon::dispatch($coupon->organization_id, $coupon->store_id, $coupon->id);
                            }
                        });
                    WebhookEvent::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)
                        ->whereHas('app', fn ($q) => $q->where('handle', config('referral.active.handle')))->where('status', 'failed')
                        ->where('attempts', '<', 10)->where('next_retry_at', '<=', now())->orderBy('id')->limit(100)->get()->each(function ($event) {
                            $event->update(['status' => 'retrying']);
                            ProcessWebhookEventJob::dispatch($event->id)->onQueue('affiliate');
                        });
                    $key = 'affiliate-recent-orders:'.$store->id;
                    if (Cache::add($key, true, 3600)) {
                        try {
                            app(AffiliateReconciliationService::class)->enqueueRecent($store);
                        } catch (\Throwable $e) {
                            Cache::forget($key);
                            $this->warn('Referral order reconciliation needs attention; no credentials logged.');
                        }
                    }
                });
            }
            if (! $this->option('loop')) {
                break;
            }
            sleep(60);
        } while (true);

        return self::SUCCESS;
    }
}
