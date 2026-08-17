<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['organization_id', 'name', 'handle', 'slug', 'client_id', 'client_secret_encrypted', 'distribution', 'type', 'status', 'description', 'scopes', 'redirect_uris', 'webhook_api_version', 'settings'])]
#[Hidden(['client_secret_encrypted'])]
class App extends Model
{
    use SoftDeletes;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function installations(): HasMany
    {
        return $this->hasMany(AppInstallation::class);
    }

    /**
     * Keep the App Center vocabulary compatible with the existing OAuth schema.
     */
    protected function slug(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->handle,
            set: fn (string $value): array => ['handle' => $value],
        );
    }

    /**
     * The existing distribution column is the registry's app type.
     */
    protected function type(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->distribution,
            set: fn (string $value): array => ['distribution' => $value],
        );
    }

    protected function casts(): array
    {
        return [
            'client_secret_encrypted' => 'encrypted',
            'scopes' => 'array',
            'redirect_uris' => 'array',
            'settings' => 'array',
        ];
    }
}
