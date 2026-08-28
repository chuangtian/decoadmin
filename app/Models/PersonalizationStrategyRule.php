<?php

namespace App\Models;

use App\Enums\PersonalizationRuleType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'store_id', 'strategy_id', 'type', 'value', 'enabled', 'position'])]
class PersonalizationStrategyRule extends Model
{
    public function strategy(): BelongsTo
    {
        return $this->belongsTo(PersonalizationRecommendationStrategy::class, 'strategy_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    protected function casts(): array
    {
        return [
            'type' => PersonalizationRuleType::class,
            'value' => 'array',
            'enabled' => 'boolean',
            'position' => 'integer',
        ];
    }
}
