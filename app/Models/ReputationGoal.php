<?php

namespace App\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['uuid', 'organization_id', 'store_id', 'month', 'metric', 'target_value', 'created_by', 'updated_by'])]
class ReputationGoal extends Model
{
    use ScopesToOrganizationStore;

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    protected static function booted(): void
    {
        static::creating(function (ReputationGoal $goal): void {
            $goal->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['month' => 'date', 'target_value' => 'float'];
    }
}
