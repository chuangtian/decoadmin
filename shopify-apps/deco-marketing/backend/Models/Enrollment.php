<?php

namespace DecoMarketing\Models;

class Enrollment extends Record
{
    protected $table = 'marketing_enrollments';

    protected $hidden = ['context_encrypted'];

    protected function casts(): array
    {
        return ['context_encrypted' => 'encrypted:array', 'steps' => 'array', 'step' => 'integer', 'next_at' => 'datetime'];
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }
}
