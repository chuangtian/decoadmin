<?php

namespace CommunityReviews\Models;

use App\Models\ModelAssetFolder;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelLink extends Model
{
    protected $table = 'community_review_models';

    protected $guarded = ['id'];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(ModelAssetFolder::class, 'folder_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'aliases' => 'array'];
    }
}
