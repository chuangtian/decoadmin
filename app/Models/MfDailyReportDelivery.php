<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'store_id', 'source_table_id', 'source_record_id',
    'report_date', 'status', 'attempts', 'message_hash', 'last_error', 'sent_at',
])]
class MfDailyReportDelivery extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }
}
