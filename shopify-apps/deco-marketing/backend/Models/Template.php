<?php

namespace DecoMarketing\Models;

class Template extends Record
{
    protected $table = 'marketing_templates';

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'draft' => 'array', 'published' => 'array', 'version' => 'integer', 'tested_at' => 'datetime'];
    }
}
