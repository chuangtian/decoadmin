<?php

namespace DecoMarketing\Models;

class Attribution extends Record
{
    protected $table = 'marketing_attributions';

    protected function casts(): array
    {
        return ['ordered_at' => 'datetime'];
    }
}
