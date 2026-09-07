<?php

namespace CommunityReviews\Models;

use Illuminate\Database\Eloquent\Model;

class Installation extends Model
{
    protected $table = 'community_review_installations';

    protected $guarded = ['id'];

    protected $hidden = ['access_token_encrypted'];

    protected function casts(): array
    {
        return ['access_token_encrypted' => 'encrypted', 'granted_scopes' => 'array', 'installed_at' => 'datetime'];
    }
}
