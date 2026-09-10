<?php

namespace DecoMarketing\Models;

class CheckoutSnapshot extends Record
{
    protected $table = 'marketing_checkout_snapshots';

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
