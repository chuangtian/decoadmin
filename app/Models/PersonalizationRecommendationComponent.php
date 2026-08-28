<?php

namespace App\Models;

use App\Enums\PersonalizationComponentStatus;
use App\Enums\PersonalizationPlacement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable(['uuid', 'organization_id', 'store_id', 'strategy_id', 'name', 'placement', 'status', 'heading', 'button_label', 'position', 'settings', 'published_at', 'created_by', 'updated_by'])]
class PersonalizationRecommendationComponent extends Model
{
    use SoftDeletes;

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

    public function style(): HasOne
    {
        return $this->hasOne(PersonalizationComponentStyle::class, 'component_id');
    }

    protected static function booted(): void
    {
        static::creating(function (PersonalizationRecommendationComponent $component): void {
            $component->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'placement' => PersonalizationPlacement::class,
            'status' => PersonalizationComponentStatus::class,
            'position' => 'integer',
            'settings' => 'array',
            'published_at' => 'datetime',
        ];
    }
}
