<?php

namespace App\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'organization_id', 'store_id', 'meta_account_id', 'name', 'account_status', 'currency',
    'timezone_name', 'timezone_offset_hours_utc', 'business_name', 'disable_reason',
    'spend_cap', 'amount_spent', 'balance', 'raw_payload', 'last_seen_at', 'synced_at',
])]
class MetaAdAccount extends Model
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

    public function campaigns(): HasMany
    {
        return $this->hasMany(MetaAdCampaign::class);
    }

    public function adSets(): HasMany
    {
        return $this->hasMany(MetaAdSet::class);
    }

    public function ads(): HasMany
    {
        return $this->hasMany(MetaAd::class);
    }

    public function creatives(): HasMany
    {
        return $this->hasMany(MetaAdCreative::class);
    }

    public function insights(): HasMany
    {
        return $this->hasMany(MetaAdInsight::class);
    }

    protected function casts(): array
    {
        return [
            'account_status' => 'integer',
            'disable_reason' => 'integer',
            'raw_payload' => 'array',
            'last_seen_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }
}
