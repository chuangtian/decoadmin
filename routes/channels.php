<?php

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
