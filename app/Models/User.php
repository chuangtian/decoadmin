<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'status', 'timezone', 'locale', 'avatar_url', 'metadata'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_users')
            ->withPivot(['status', 'job_title', 'invited_by', 'joined_at', 'deleted_at'])
            ->wherePivot('status', 'active')
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot(['organization_id', 'store_id', 'granted_by', 'expires_at', 'deleted_at'])
            ->wherePivotNull('deleted_at')
            ->where(function (Builder $query): void {
                $query->whereNull('user_roles.expires_at')
                    ->orWhere('user_roles.expires_at', '>', now());
            })
            ->withTimestamps();
    }

    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'store_members')
            ->withPivot(['status', 'invited_by', 'joined_at', 'deleted_at'])
            ->wherePivot('status', 'active')
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }

    /**
     * Query every permission granted by a currently active role assignment.
     */
    public function permissions(): Builder
    {
        return Permission::query()->whereHas('roles.users', function (Builder $query): void {
            $query->whereKey($this->getKey());
        });
    }

    public function hasRole(string|Role $role, ?Organization $organization = null, ?Store $store = null): bool
    {
        $slug = $role instanceof Role ? $role->slug : $role;

        return $this->roles()
            ->where('roles.slug', $slug)
            ->when($organization, fn (Builder $query) => $query->where('user_roles.organization_id', $organization->getKey()))
            ->when(
                $store,
                fn (Builder $query) => $query->where(function (Builder $query) use ($store): void {
                    $query->whereNull('user_roles.store_id')
                        ->orWhere('user_roles.store_id', $store->getKey());
                }),
                fn (Builder $query) => $organization ? $query->whereNull('user_roles.store_id') : $query,
            )
            ->exists();
    }

    public function hasPermission(string|Permission $permission, ?Organization $organization = null, ?Store $store = null): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($organization && ! $this->organizations()->whereKey($organization->getKey())->exists()) {
            return false;
        }

        if ($store && ($store->organization_id !== $organization?->getKey() || ! $this->canAccessStore($store))) {
            return false;
        }

        $slug = $permission instanceof Permission ? $permission->slug : $permission;

        return $this->roles()
            ->when($organization, fn (Builder $query) => $query->where('user_roles.organization_id', $organization->getKey()))
            ->when(
                $store,
                fn (Builder $query) => $query->where(function (Builder $query) use ($store): void {
                    $query->whereNull('user_roles.store_id')
                        ->orWhere('user_roles.store_id', $store->getKey());
                }),
                fn (Builder $query) => $organization ? $query->whereNull('user_roles.store_id') : $query,
            )
            ->whereHas('permissions', fn (Builder $query) => $query->where('permissions.slug', $slug))
            ->exists();
    }

    public function canAccessStore(Store $store): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->organizations()->whereKey($store->organization_id)->exists()
            && $this->stores()->whereKey($store->getKey())->exists();
    }

    public function isSuperAdmin(): bool
    {
        if ((bool) data_get($this->metadata, 'is_super_admin', false)) {
            return true;
        }

        return $this->roles()->where('roles.slug', 'super-admin')->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'metadata' => 'array',
            'password' => 'hashed',
        ];
    }
}
