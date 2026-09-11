<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'uuid', 'organization_id', 'store_id', 'reference_no', 'task_name', 'request_type', 'priority', 'description',
    'requester_department', 'requester_id', 'designer_id', 'quantity', 'requested_on',
    'planned_delivery_date', 'actual_delivery_date', 'status', 'revision_count', 'delivery_note', 'updated_by',
])]
class DesignRequest extends Model
{
    use SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (DesignRequest $request): void {
            $request->uuid ??= (string) Str::uuid();
            $request->reference_no ??= 'DR-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function designer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'designer_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(DesignRequestAttachment::class)->orderBy('sort_order')->orderBy('id');
    }

    public function progressLogs(): HasMany
    {
        return $this->hasMany(DesignRequestProgressLog::class)->orderBy('created_at')->orderBy('id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'requested_on' => 'date', 'planned_delivery_date' => 'date', 'actual_delivery_date' => 'date',
            'quantity' => 'integer', 'revision_count' => 'integer',
        ];
    }
}
