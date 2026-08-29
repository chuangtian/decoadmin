<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['uuid', 'organization_id', 'store_id', 'default_locale', 'copy', 'attribution', 'updated_by'])]
class PersonalizationGlobalSetting extends Model
{
    protected static function booted(): void
    {
        static::creating(function (PersonalizationGlobalSetting $setting): void {
            $setting->uuid ??= (string) Str::uuid();
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    protected function casts(): array
    {
        return [
            'copy' => 'array',
            'attribution' => 'array',
        ];
    }
}
