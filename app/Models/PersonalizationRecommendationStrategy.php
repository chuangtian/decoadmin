<?php

namespace App\Models;

use App\Enums\PersonalizationAlgorithm;
use App\Enums\PersonalizationStrategyStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable(['uuid', 'organization_id', 'store_id', 'name', 'algorithm', 'enabled', 'status', 'published_version_id', 'item_limit', 'settings', 'archived_at', 'purge_after', 'created_by', 'updated_by'])]
class PersonalizationRecommendationStrategy extends Model
{
    use SoftDeletes;

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(PersonalizationStrategyRule::class, 'strategy_id')->orderBy('position');
    }

    public function productOverrides(): HasMany
    {
        return $this->hasMany(PersonalizationStrategyProductOverride::class, 'strategy_id')->orderBy('position');
    }

    public function components(): HasMany
    {
        return $this->hasMany(PersonalizationRecommendationComponent::class, 'strategy_id')->orderBy('position');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PersonalizationStrategyVersion::class, 'strategy_id')->orderByDesc('version_number');
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(PersonalizationStrategyVersion::class, 'published_version_id');
    }

    protected static function booted(): void
    {
        static::creating(function (PersonalizationRecommendationStrategy $strategy): void {
            $strategy->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'algorithm' => PersonalizationAlgorithm::class,
            'enabled' => 'boolean',
            'status' => PersonalizationStrategyStatus::class,
            'item_limit' => 'integer',
            'settings' => 'array',
            'archived_at' => 'datetime',
            'purge_after' => 'datetime',
        ];
    }
}
