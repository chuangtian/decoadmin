<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;

#[Guarded(['id'])]
class ShopifyProductMonitor extends Model
{
    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'low_stock_threshold' => 'integer', 'generation' => 'integer',
            'revision' => 'integer', 'snapshot' => 'array', 'last_checked_at' => 'datetime', 'last_success_at' => 'datetime'];
    }
}
