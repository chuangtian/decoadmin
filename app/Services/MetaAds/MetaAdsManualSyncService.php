<?php

namespace App\Services\MetaAds;

use App\Jobs\SyncMetaAdsCampaignPeriod;
use App\Jobs\SyncMetaAdsForStore;
use App\Models\MetaAdAccount;
use App\Models\MetaAdInsight;
use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Models\SyncJob;

class MetaAdsManualSyncService
{
    /**
     * @param  array{account?: string, date_from?: string, date_to?: string}  $filters
     * @return array{queued: bool, already_running: bool, configured: bool, mode: string|null, period_queued: bool}
     */
    public function queue(Store $store, array $filters = []): array
    {
        $credential = StoreBusinessCredential::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->getKey())
            ->where('provider', 'meta_ads')
            ->where('credential_key', 'access_token')
            ->first();

        if (! $credential) {
            return ['queued' => false, 'already_running' => false, 'configured' => false, 'mode' => null, 'period_queued' => false];
        }

        $periodQueued = false;
        if (isset($filters['date_from'], $filters['date_to'])) {
            SyncMetaAdsCampaignPeriod::dispatch(
                (int) $store->organization_id,
                (int) $store->getKey(),
                trim((string) ($filters['account'] ?? 'all')) ?: 'all',
                (string) $filters['date_from'],
                (string) $filters['date_to'],
                $credential->updated_at?->utc()->format('Y-m-d H:i:s') ?? '',
            );
            $periodQueued = true;
        }

        $active = SyncJob::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->getKey())
            ->where('type', MetaAdsSyncService::SYNC_TYPE)
            ->whereIn('status', ['queued', 'running'])
            ->latest('id')
            ->first();

        if ($active) {
            return [
                'queued' => false,
                'already_running' => true,
                'configured' => true,
                'mode' => $active->mode,
                'period_queued' => $periodQueued,
            ];
        }

        $hasData = MetaAdAccount::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->exists()
            || MetaAdInsight::query()
                ->forOrganization((int) $store->organization_id)
                ->forStore((int) $store->getKey())
                ->exists();
        $mode = $hasData ? 'incremental' : 'priority';

        SyncMetaAdsForStore::dispatch(
            (int) $store->organization_id,
            (int) $store->getKey(),
            $mode,
            $credential->updated_at?->utc()->format('Y-m-d H:i:s') ?? '',
            false,
        );

        return [
            'queued' => true,
            'already_running' => false,
            'configured' => true,
            'mode' => $mode,
            'period_queued' => $periodQueued,
        ];
    }
}
