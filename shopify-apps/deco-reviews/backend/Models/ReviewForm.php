<?php

namespace DecoReviews\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewForm extends Model
{
    protected $table = 'deco_review_forms';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['configuration' => 'array'];
    }
}
