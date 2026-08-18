<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'uuid', 'organization_id', 'store_id', 'app_id', 'app_installation_id', 'type', 'direction',
    'status', 'cursor', 'payload', 'result', 'logs', 'total_items', 'processed_items',
    'failed_items', 'attempts', 'max_attempts', 'available_at', 'started_at', 'finished_at',
    'completed_at', 'failed_at', 'last_error',
])]
class SyncJob extends Model
{
    use SoftDeletes;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function appInstallation(): BelongsTo
    {
        return $this->belongsTo(AppInstallation::class);
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'logs' => 'array',
            'total_items' => 'integer',
            'processed_items' => 'integer',
            'failed_items' => 'integer',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'available_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
