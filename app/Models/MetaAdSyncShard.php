<?php

namespace App\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'uuid', 'sync_job_id', 'organization_id', 'store_id', 'meta_ad_account_id', 'shard_key',
    'kind', 'level', 'mode', 'status', 'since_at', 'until_at', 'since_date', 'until_date',
    'attempts', 'records_count', 'result', 'error_code', 'last_error', 'started_at', 'finished_at',
])]
class MetaAdSyncShard extends Model
{
    use ScopesToOrganizationStore;

    public function syncJob(): BelongsTo
    {
        return $this->belongsTo(SyncJob::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MetaAdAccount::class, 'meta_ad_account_id');
    }

    protected function casts(): array
    {
        return [
            'since_at' => 'datetime',
            'until_at' => 'datetime',
            'since_date' => 'date',
            'until_date' => 'date',
            'attempts' => 'integer',
            'records_count' => 'integer',
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
