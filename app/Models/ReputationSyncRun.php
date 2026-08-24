<?php

namespace App\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'uuid', 'organization_id', 'store_id', 'requested_by', 'source', 'status',
    'progress_percent', 'processed_rows', 'result', 'last_error', 'started_at',
    'completed_at', 'failed_at',
])]
class ReputationSyncRun extends Model
{
    use ScopesToOrganizationStore;

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    protected static function booted(): void
    {
        static::creating(function (ReputationSyncRun $run): void {
            $run->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'progress_percent' => 'integer',
            'processed_rows' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
