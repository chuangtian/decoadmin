<?php

namespace CommunityReviews\Models;

use Illuminate\Database\Eloquent\Model;

class Settings extends Model
{
    protected $table = 'community_review_settings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'card_count' => 'integer'];
    }
}
