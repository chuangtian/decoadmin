<?php

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('business-notifications.{organizationId}.{userId}', function (User $user, int $organizationId, int $userId): bool {
    if ((int) $user->id !== $userId
        || $user->status !== 'active'
        || (int) request()->session()->get('current_organization_id') !== $organizationId) {
        return false;
    }

    return $user->isSuperAdmin() || $user->organizations()->whereKey($organizationId)->exists();
});

Broadcast::channel('advertising-sync.{organizationId}.{storeId}', function (User $user, int $organizationId, int $storeId): bool {
    if ($user->status !== 'active'
        || (int) request()->session()->get('current_organization_id') !== $organizationId
        || (int) request()->session()->get('current_store_id') !== $storeId) {
        return false;
    }

    $store = Store::query()
        ->where('organization_id', $organizationId)
        ->whereKey($storeId)
        ->where('status', 'active')
        ->first();

    return $store instanceof Store
        && $user->hasPermission('reports.view', $store->organization, $store);
});
