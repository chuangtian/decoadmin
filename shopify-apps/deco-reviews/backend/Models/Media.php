<?php

namespace DecoReviews\Models;

use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    protected $table = 'deco_review_media';

    protected $guarded = ['id'];

    protected $hidden = ['path'];

    public function review()
    {
        return $this->belongsTo(Review::class);
    }
}
