<?php

namespace DecoMarketing\Models;

class OrderSnapshot extends Record
{
    protected $table = 'marketing_order_snapshots';

    protected function casts(): array
    {
        return ['ordered_at' => 'datetime', 'cancelled_at' => 'datetime', 'total' => 'decimal:2', 'refunded' => 'decimal:2'];
    }
}
