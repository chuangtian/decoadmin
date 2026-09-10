<?php

namespace DecoMarketing\Models;

class Contact extends Record
{
    protected $table = 'marketing_contacts';

    protected $hidden = ['email_encrypted', 'name_encrypted', 'email_hash'];

    protected function casts(): array
    {
        return ['first_subscribed_at' => 'datetime', 'email_encrypted' => 'encrypted', 'name_encrypted' => 'encrypted', 'consent_at' => 'datetime', 'suppressed' => 'boolean', 'last_sent_at' => 'datetime', 'orders_count' => 'integer'];
    }

    public function deliveries()
    {
        return $this->hasMany(Delivery::class);
    }
}
