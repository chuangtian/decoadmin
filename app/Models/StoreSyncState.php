<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'store_id', 'sync_type', 'status', 'watermark_at',
    'last_full_sync_at', 'last_incremental_sync_at', 'last_reconciled_at',
    'last_success_at', 'last_failed_at', 'last_job_id', 'consecutive_failures',
    'next_sync_at', 'last_metric_date', 'data_synced_at', 'last_error_code', 'last_error',
])]
class StoreSyncState extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function lastJob(): BelongsTo
    {
        return $this->belongsTo(SyncJob::class, 'last_job_id');
    }

    protected function casts(): array
    {
        return [
            'watermark_at' => 'datetime',
            'last_full_sync_at' => 'datetime',
            'last_incremental_sync_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'next_sync_at' => 'datetime',
            'last_metric_date' => 'date',
            'data_synced_at' => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }
}
