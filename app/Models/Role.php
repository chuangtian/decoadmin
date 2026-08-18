<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['organization_id', 'name', 'slug', 'description', 'is_system'])]
class Role extends Model
{
    use SoftDeletes;

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')
            ->withPivot(['organization_id', 'store_id', 'granted_by', 'expires_at', 'deleted_at'])
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function hasPermission(string|Permission $permission): bool
    {
        $slug = $permission instanceof Permission ? $permission->slug : $permission;

        return $this->permissions()->where('permissions.slug', $slug)->exists();
    }

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }
}
