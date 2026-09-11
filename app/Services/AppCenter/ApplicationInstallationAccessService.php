<?php

namespace App\Services\AppCenter;

use App\Models\AppInstallation;
use App\Models\Organization;
use App\Models\Store;

class ApplicationInstallationAccessService
{
    public const REFERRAL = 'referral';

    public function isActive(string $application, Organization $organization, Store $store): bool
    {
        if ((int) $store->organization_id !== (int) $organization->getKey()) {
            return false;
        }

        $handle = $this->handle($application);
        if ($handle === null) {
            return false;
        }

        return AppInstallation::query()
            ->where('store_id', $store->getKey())
            ->where('status', 'active')
            ->whereHas('app', fn ($query) => $query
                ->where('handle', $handle)
                ->where('status', 'active')
                ->where(function ($query) use ($organization): void {
                    $query->whereNull('organization_id')
                        ->orWhere('organization_id', $organization->getKey());
                }))
            ->exists();
    }

    private function handle(string $application): ?string
    {
        return match ($application) {
            self::REFERRAL => filled(config('referral.active.handle'))
                ? (string) config('referral.active.handle')
                : null,
            default => null,
        };
    }
}
