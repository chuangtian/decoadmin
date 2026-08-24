<?php

namespace App\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'uuid', 'organization_id', 'store_id', 'description', 'request_type', 'priority',
    'owner_name', 'status', 'due_date', 'created_by', 'updated_by',
])]
class ReputationResourceRequest extends Model
{
    use ScopesToOrganizationStore;

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function (ReputationResourceRequest $request): void {
            $request->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['due_date' => 'date'];
    }
}
