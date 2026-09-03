<?php

namespace App\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'uuid', 'organization_id', 'store_id', 'week_start', 'week_end', 'title', 'status',
    'summary', 'metrics_snapshot', 'published_at', 'created_by', 'updated_by',
])]
class BrandSocialWeeklyReport extends Model
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
        static::creating(function (BrandSocialWeeklyReport $report): void {
            $report->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'week_end' => 'date',
            'metrics_snapshot' => 'array',
            'published_at' => 'datetime',
        ];
    }
}
