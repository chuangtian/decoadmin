<?php

namespace DecoReviews\Models;

use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model
{
    protected $table = 'deco_review_imports';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['errors' => 'array', 'undone_at' => 'datetime'];
    }
}
