<?php

namespace App\Services\AppCenter;

use App\Models\App;
use App\Models\AppInstallation;
use App\Models\Organization;
use App\Models\Store;

class ApplicationCenterNavigationService
{
    /**
     * @param  list<string>  $permissions
     * @return list<array{app_id: int, name: string, route: string, permission: string}>
     */
    public function forStore(Organization $organization, Store $store, array $permissions): array
    {
        if ((int) $store->organization_id !== (int) $organization->getKey()) {
            return [];
        }

        $latestInstallationIds = AppInstallation::query()
            ->selectRaw('MAX(id)')
            ->where('store_id', $store->getKey())
            ->groupBy('app_id');

        return AppInstallation::query()
            ->whereIn('id', $latestInstallationIds)
            ->where('status', 'active')
            ->whereHas('app', fn ($query) => $query
                ->where('status', 'active')
                ->where(function ($query) use ($organization): void {
                    $query->whereNull('organization_id')
                        ->orWhere('organization_id', $organization->getKey());
                }))
            ->with('app:id,organization_id,name,handle,status')
            ->get()
            ->map(fn (AppInstallation $installation): ?array => $this->item(
                $installation->app,
                $organization,
                $store,
                $permissions,
            ))
            ->filter()
            ->sortBy(fn (array $item): string => mb_strtolower($item['name']))
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $permissions
     * @return array{app_id: int, name: string, route: string, permission: string}|null
     */
    private function item(
        ?App $app,
        Organization $organization,
        Store $store,
        array $permissions,
    ): ?array {
        if (! $app) {
            return null;
        }

        $known = $this->knownApplication($app->handle, $organization, $store);
        $permission = $known['permission'] ?? 'apps.view';
        if (! in_array($permission, $permissions, true)) {
            return null;
        }

        return [
            'app_id' => (int) $app->getKey(),
            'name' => $known['name'] ?? $app->name,
            'route' => $known['route'] ?? route('app-center.show', $app, false),
            'permission' => $permission,
        ];
    }

    /** @return array{name: string, route: string, permission: string}|null */
    private function knownApplication(string $handle, Organization $organization, Store $store): ?array
    {
        $base = "/organizations/{$organization->getKey()}/stores/{$store->getKey()}";

        return match (true) {
            str_starts_with($handle, 'deco-marketing-') => [
                'name' => '营销自动化',
                'route' => $base.'/marketing',
                'permission' => 'marketing.view',
            ],
            str_starts_with($handle, 'deco-personalization') => [
                'name' => '个性化推荐',
                'route' => $base.'/personalization',
                'permission' => 'personalization.view',
            ],
            str_starts_with($handle, 'deco-student-discount') => [
                'name' => '学生优惠',
                'route' => $base.'/student-discounts',
                'permission' => 'student_discount.claim.read',
            ],
            str_starts_with($handle, 'deco-instagram-feed') => [
                'name' => 'Instagram Feed',
                'route' => $base.'/instagram-feed',
                'permission' => 'instagram_feed.view',
            ],
            default => null,
        };
    }
}
