<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'store_id', 'enabled', 'code_prefix', 'discount_type', 'discount_value',
    'applies_to', 'target_ids', 'combines_with_order_discounts', 'combines_with_product_discounts',
    'combines_with_shipping_discounts', 'usage_limit', 'validity_days', 'education_email_domains', 'email_templates',
    'created_by', 'updated_by',
])]
class StudentDiscountCampaign extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'discount_value' => 'decimal:2',
            'target_ids' => 'array',
            'combines_with_order_discounts' => 'boolean',
            'combines_with_product_discounts' => 'boolean',
            'combines_with_shipping_discounts' => 'boolean',
            'usage_limit' => 'integer',
            'validity_days' => 'integer',
            'education_email_domains' => 'array',
            'email_templates' => 'array',
        ];
    }
}
