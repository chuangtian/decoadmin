<?php

namespace DecoReviews\Models;

use Illuminate\Database\Eloquent\Model;

class Installation extends Model
{
    protected $table = 'deco_review_installations';

    protected $guarded = ['id'];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'expires_at' => 'datetime'];
    }
}
