<?php

namespace DecoMarketing\Models;

class Campaign extends Record
{
    protected $table = 'marketing_campaigns';

    protected function casts(): array
    {
        return ['smart_timing' => 'boolean', 'content' => 'array', 'variant_b' => 'array', 'test_percent' => 'integer', 'scheduled_at' => 'datetime', 'started_at' => 'datetime', 'expanded_at' => 'datetime', 'winner_at' => 'datetime', 'test_ends_at' => 'datetime'];
    }
}
