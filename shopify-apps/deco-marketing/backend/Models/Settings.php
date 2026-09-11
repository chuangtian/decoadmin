<?php

namespace DecoMarketing\Models;

class Settings extends Record
{
    protected $table = 'marketing_settings';

    protected function casts(): array
    {
        return ['warmup_enabled' => 'boolean', 'warmup_steps' => 'array', 'enabled' => 'boolean', 'daily_limit' => 'integer', 'frequency_hours' => 'integer', 'sync_state' => 'array', 'popup' => 'array', 'coupons' => 'array', 'cutover_at' => 'datetime'];
    }
}
