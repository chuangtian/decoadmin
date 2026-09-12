<?php

namespace DecoReviews\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Review extends Model
{
    protected $table = 'deco_reviews';

    protected $guarded = ['id'];

    protected $hidden = ['author_email', 'email_hash', 'fingerprint'];

    protected function casts(): array
    {
        return ['author_email' => 'encrypted', 'featured' => 'boolean', 'incentivized' => 'boolean',
            'rating' => 'integer', 'publish_at' => 'datetime', 'published_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }
}
