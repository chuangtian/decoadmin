<?php

namespace DecoMarketing\Models;

class Flow extends Record
{
    protected $table = 'marketing_flows';

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'steps' => 'array', 'version' => 'integer'];
    }
}
