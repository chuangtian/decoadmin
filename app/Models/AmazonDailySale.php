<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'store_id', 'source_record_id', 'sale_date', 'weekday',
    'fba_order_count', 'fba_sales', 'fbm_order_count', 'fbm_sales', 'total_sales',
    'accessory_refunds', 'refunds', 'total_refunds', 'net_sales', 'refund_rate',
    'ad_spend', 'ad_spend_rate', 'roi',
    'sp_impressions', 'sp_clicks', 'sp_click_through_rate', 'sp_spend', 'sp_ad_sales', 'sp_order_count', 'sp_acos', 'sp_conversion_rate',
    'sb_impressions', 'sb_clicks', 'sb_click_through_rate', 'sb_spend', 'sb_ad_sales', 'sb_order_count', 'sb_acos', 'sb_conversion_rate',
    'sd_impressions', 'sd_clicks', 'sd_click_through_rate', 'sd_spend', 'sd_ad_sales', 'sd_order_count', 'sd_acos', 'sd_conversion_rate',
    'remarks', 'source_created_at', 'source_updated_at', 'synced_at',
])]
class AmazonDailySale extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
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
            'sale_date' => 'date',
            'remarks' => 'array',
            'source_created_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'synced_at' => 'datetime',
            'fba_sales' => 'decimal:4',
            'fbm_sales' => 'decimal:4',
            'total_sales' => 'decimal:4',
            'accessory_refunds' => 'decimal:4',
            'refunds' => 'decimal:4',
            'total_refunds' => 'decimal:4',
            'net_sales' => 'decimal:4',
            'refund_rate' => 'decimal:6',
            'ad_spend' => 'decimal:4',
            'ad_spend_rate' => 'decimal:6',
            'roi' => 'decimal:6',
            'sp_click_through_rate' => 'decimal:6',
            'sp_spend' => 'decimal:4',
            'sp_ad_sales' => 'decimal:4',
            'sp_acos' => 'decimal:6',
            'sp_conversion_rate' => 'decimal:6',
            'sb_click_through_rate' => 'decimal:6',
            'sb_spend' => 'decimal:4',
            'sb_ad_sales' => 'decimal:4',
            'sb_acos' => 'decimal:6',
            'sb_conversion_rate' => 'decimal:6',
            'sd_click_through_rate' => 'decimal:6',
            'sd_spend' => 'decimal:4',
            'sd_ad_sales' => 'decimal:4',
            'sd_acos' => 'decimal:6',
            'sd_conversion_rate' => 'decimal:6',
        ];
    }
}
