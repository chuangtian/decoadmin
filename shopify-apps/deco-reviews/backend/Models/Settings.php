<?php

namespace DecoReviews\Models;

use Illuminate\Database\Eloquent\Model;

class Settings extends Model
{
    protected $table = 'deco_review_settings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['values' => 'array'];
    }
}
