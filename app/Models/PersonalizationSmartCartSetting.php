<?php

namespace App\Models;

use App\Enums\PersonalizationSmartCartCompatibilityStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['uuid', 'organization_id', 'store_id', 'strategy_id', 'enabled', 'compatibility_status', 'compatibility_details', 'compatibility_checked_at', 'theme_id', 'theme_name', 'preview_confirmed_at', 'enabled_at', 'disabled_at', 'fallback_mode', 'settings', 'updated_by'])]
class PersonalizationSmartCartSetting extends Model
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
        static::creating(function (PersonalizationSmartCartSetting $setting): void {
            $setting->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'compatibility_status' => PersonalizationSmartCartCompatibilityStatus::class,
            'compatibility_details' => 'array',
            'compatibility_checked_at' => 'datetime',
            'preview_confirmed_at' => 'datetime',
            'enabled_at' => 'datetime',
            'disabled_at' => 'datetime',
            'settings' => 'array',
        ];
    }
}
