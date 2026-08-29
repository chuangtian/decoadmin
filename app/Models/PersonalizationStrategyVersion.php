<?php

namespace App\Models;

use App\Enums\PersonalizationAlgorithm;
use App\Enums\PersonalizationStrategyVersionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'uuid', 'organization_id', 'store_id', 'strategy_id', 'version_number', 'status',
    'name', 'algorithm', 'item_limit', 'configuration', 'checksum', 'lock_version',
    'created_by', 'published_by', 'published_at',
])]
class PersonalizationStrategyVersion extends Model
{
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(PersonalizationRecommendationStrategy::class, 'strategy_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    protected static function booted(): void
    {
        static::creating(function (PersonalizationStrategyVersion $version): void {
            $version->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'status' => PersonalizationStrategyVersionStatus::class,
            'algorithm' => PersonalizationAlgorithm::class,
            'item_limit' => 'integer',
            'configuration' => 'array',
            'lock_version' => 'integer',
            'published_at' => 'datetime',
        ];
    }
}
