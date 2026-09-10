<?php

namespace DecoMarketing\Models;

class Delivery extends Record
{
    protected $table = 'marketing_deliveries';

    protected $hidden = ['payload_encrypted'];

    protected function casts(): array
    {
        return ['human_opened_at' => 'datetime', 'machine_opened_at' => 'datetime', 'payload_encrypted' => 'encrypted:array', 'step' => 'integer', 'attempts' => 'integer', 'first_attempt_at' => 'datetime', 'lease_until' => 'datetime', 'retry_at' => 'datetime', 'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'opened_at' => 'datetime', 'clicked_at' => 'datetime', 'unsubscribed_at' => 'datetime'];
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }
}
