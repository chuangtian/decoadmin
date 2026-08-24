<?php

namespace App\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'uuid', 'organization_id', 'store_id', 'requested_by', 'source', 'mode', 'status', 'date_from', 'date_to',
    'progress_percent', 'processed_rows', 'result', 'last_error', 'started_at', 'completed_at', 'failed_at',
])]
class SeoAnalyticsSyncRun extends Model
{
    use ScopesToOrganizationStore;

    protected function casts(): array
    {
        return [
            'date_from' => 'date', 'date_to' => 'date', 'progress_percent' => 'integer', 'processed_rows' => 'integer',
            'result' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'failed_at' => 'datetime',
        ];
    }
}
