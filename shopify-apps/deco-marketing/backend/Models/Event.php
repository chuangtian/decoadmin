<?php

namespace DecoMarketing\Models;

class Event extends Record
{
    protected $table = 'marketing_events';

    protected $hidden = ['payload_encrypted'];

    protected function casts(): array
    {
        return ['payload_encrypted' => 'encrypted:array', 'processed_at' => 'datetime', 'retry_at' => 'datetime', 'attempts' => 'integer'];
    }
}
