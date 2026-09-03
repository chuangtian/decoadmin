<?php

namespace App\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'uuid', 'organization_id', 'store_id', 'review_date', 'status', 'core_data',
    'top_content', 'low_content', 'recommendations', 'published_at', 'created_by', 'updated_by',
])]
class BrandSocialDailyReview extends Model
{
    use ScopesToOrganizationStore;

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function (BrandSocialDailyReview $review): void {
            $review->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'review_date' => 'date',
            'published_at' => 'datetime',
        ];
    }
}
