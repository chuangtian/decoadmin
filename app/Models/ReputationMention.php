<?php

namespace App\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'uuid', 'organization_id', 'store_id', 'origin', 'source', 'canonical_key', 'source_sheets',
    'source_payloads_encrypted', 'url', 'url_hash', 'title', 'content', 'rating',
    'week_number', 'published_at', 'model_name', 'order_reference_encrypted',
    'processing_status', 'response_note', 'metrics', 'is_negative', 'is_active', 'synced_at',
])]
#[Hidden(['source_payloads_encrypted', 'order_reference_encrypted'])]
class ReputationMention extends Model
{
    use ScopesToOrganizationStore;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function risks(): HasMany
    {
        return $this->hasMany(ReputationRisk::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function (ReputationMention $mention): void {
            $mention->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'source_sheets' => 'array',
            'source_payloads_encrypted' => 'encrypted:array',
            'order_reference_encrypted' => 'encrypted',
            'response_note' => 'encrypted',
            'metrics' => 'array',
            'rating' => 'float',
            'week_number' => 'integer',
            'published_at' => 'datetime',
            'is_negative' => 'boolean',
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }
}
