<?php

namespace DecoMarketing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;

class PopupEvent extends Record
{
    protected $table = 'marketing_popup_events';

    protected function day(): Attribute
    {
        return Attribute::make(set: fn ($value) => CarbonImmutable::parse($value)->toDateString());
    }
}
