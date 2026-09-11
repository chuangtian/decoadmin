<?php

namespace DecoMarketing\Models;

class Waitlist extends Record
{
    protected $table = 'marketing_waitlist';

    protected function casts(): array
    {
        return ['notified_at' => 'datetime'];
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }
}
