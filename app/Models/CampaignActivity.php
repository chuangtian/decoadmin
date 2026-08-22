<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'organization_id', 'store_id', 'source_record_id',
    'campaign_id', 'starts_on', 'ends_on', 'campaign_name', 'main_title', 'subtitle',
    'planning_document', 'core_offer', 'campaign_images', 'email_content',
    'sales_amount', 'ad_spend', 'roi', 'order_count', 'store_visits', 'conversion_rate',
    'daily_average_sales', 'daily_average_store_visits', 'daily_average_order_count', 'daily_average_ad_spend',
    'campaign_summary', 'problem_diagnosis', 'optimization_analysis', 'single_select', 'parent_records',
    'unmapped_fields', 'source_created_at', 'source_updated_at', 'synced_at',
])]
class CampaignActivity extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function planningDocumentSnapshot(): HasOne
    {
        return $this->hasOne(CampaignPlanningDocument::class);
    }

    public function scopeForOrganization(Builder $query, Organization|int $organization): void
    {
        $query->where('organization_id', $organization instanceof Organization ? $organization->getKey() : $organization);
    }

    public function scopeForStore(Builder $query, Store|int $store): void
    {
        $query->where('store_id', $store instanceof Store ? $store->getKey() : $store);
    }

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'campaign_images' => 'array',
            'email_content' => 'array',
            'parent_records' => 'array',
            'unmapped_fields' => 'array',
            'source_created_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'synced_at' => 'datetime',
            'sales_amount' => 'decimal:4',
            'ad_spend' => 'decimal:4',
            'roi' => 'decimal:6',
            'conversion_rate' => 'decimal:10',
            'daily_average_sales' => 'decimal:4',
            'daily_average_store_visits' => 'decimal:4',
            'daily_average_order_count' => 'decimal:4',
            'daily_average_ad_spend' => 'decimal:4',
        ];
    }
}
