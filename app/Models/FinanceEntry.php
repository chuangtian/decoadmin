<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'uuid', 'organization_id', 'store_id', 'category_id', 'type', 'amount', 'currency',
    'occurred_on', 'description', 'reference', 'created_by', 'updated_by',
])]
class FinanceEntry extends Model
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function booted(): void
    {
        static::creating(function (FinanceEntry $entry): void {
            $entry->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'occurred_on' => 'date',
        ];
    }
}
