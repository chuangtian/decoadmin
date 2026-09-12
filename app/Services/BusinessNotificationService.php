<?php

namespace App\Services;

use App\Events\BusinessNotificationCreated;
use App\Models\BusinessNotification;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class BusinessNotificationService
{
    /** @return array<int, int> */
    public function usersWithPermission(
        Organization $organization,
        string $permission,
        array $requiredRoles = [],
        ?Store $store = null,
    ): array {
        if ($store && (int) $store->organization_id !== (int) $organization->id) {
            return [];
        }

        return User::query()
            ->whereNull('users.deleted_at')
            ->where('users.status', 'active')
            ->where(function (Builder $query) use ($organization): void {
                $query->whereJsonContains('users.metadata->is_super_admin', true)
                    ->orWhereHas('organizations', fn (Builder $query) => $query->whereKey($organization->id));
            })
            ->when($store, fn (Builder $query) => $query->where(function (Builder $query) use ($store): void {
                $query->whereJsonContains('users.metadata->is_super_admin', true)
                    ->orWhereHas('stores', fn (Builder $query) => $query->whereKey($store->id));
            }))
            ->where(function (Builder $query) use ($organization, $permission, $store): void {
                $query->whereJsonContains('users.metadata->is_super_admin', true)
                    ->orWhereHas('roles', fn (Builder $query) => $query
                        ->where('user_roles.organization_id', $organization->id)
                        ->when($store, fn (Builder $query) => $query->where(function (Builder $query) use ($store): void {
                            $query->whereNull('user_roles.store_id')
                                ->orWhere('user_roles.store_id', $store->id);
                        }))
                        ->whereHas('permissions', fn (Builder $query) => $query->where('permissions.slug', $permission)));
            })
            ->when($requiredRoles !== [], function (Builder $query) use ($organization, $requiredRoles, $store): void {
                $query->where(function (Builder $query) use ($organization, $requiredRoles, $store): void {
                    if (in_array('super-admin', $requiredRoles, true)) {
                        $query->whereJsonContains('users.metadata->is_super_admin', true)
                            ->orWhereHas('roles', fn (Builder $query) => $query
                                ->where('user_roles.organization_id', $organization->id)
                                ->when($store, fn (Builder $query) => $query->where(function (Builder $query) use ($store): void {
                                    $query->whereNull('user_roles.store_id')
                                        ->orWhere('user_roles.store_id', $store->id);
                                }))
                                ->whereIn('roles.slug', $requiredRoles));
                    } else {
                        $query->whereHas('roles', fn (Builder $query) => $query
                            ->where('user_roles.organization_id', $organization->id)
                            ->when($store, fn (Builder $query) => $query->where(function (Builder $query) use ($store): void {
                                $query->whereNull('user_roles.store_id')
                                    ->orWhere('user_roles.store_id', $store->id);
                            }))
                            ->whereIn('roles.slug', $requiredRoles));
                    }
                });
            })
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  iterable<int>  $recipientIds
     */
    public function notify(
        Organization $organization,
        iterable $recipientIds,
        ?User $actor,
        string $type,
        string $title,
        string $message,
        string $actionUrl,
        Model $subject,
        string $eventKey,
    ): void {
        $ids = collect($recipientIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0 && $id !== (int) $actor?->id)
            ->unique()
            ->values();

        foreach ($ids as $userId) {
            $notification = BusinessNotification::query()->firstOrCreate(
                ['dedupe_key' => hash('sha256', "{$organization->id}:{$userId}:{$eventKey}")],
                [
                    'organization_id' => $organization->id,
                    'user_id' => $userId,
                    'actor_id' => $actor?->id,
                    'type' => $type,
                    'title' => $title,
                    'message' => $message,
                    'action_url' => $actionUrl,
                    'subject_type' => $subject::class,
                    'subject_id' => $subject->getKey(),
                ],
            );

            if ($notification->wasRecentlyCreated) {
                DB::afterCommit(function () use ($notification): void {
                    rescue(fn () => broadcast(new BusinessNotificationCreated($notification)), report: true);
                });
            }
        }
    }
}
