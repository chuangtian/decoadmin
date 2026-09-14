<?php

namespace DecoReviews\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ReviewGroup extends Model
{
    protected $table = 'deco_review_groups';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'deco_review_group_products', 'group_id', 'product_id')
            ->withPivot(['organization_id', 'store_id'])
            ->withTimestamps();
    }
}
