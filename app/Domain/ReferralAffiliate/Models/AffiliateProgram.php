<?php

namespace App\Domain\ReferralAffiliate\Models;

use App\Domain\ReferralAffiliate\Enums\ProgramStatus;
use App\Domain\ReferralAffiliate\Enums\ProgramType;
use App\Models\Concerns\ScopesToOrganizationStore;
use App\Models\Organization;
use App\Models\Store;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'organization_id', 'store_id', 'name', 'type', 'status', 'attribution_model', 'attribution_window_days', 'hold_days', 'currency', 'coupon_enabled', 'customer_discount_type', 'customer_discount_rate_basis_points', 'customer_discount_amount_minor', 'settings', 'starts_at', 'ends_at', 'created_by', 'updated_by'])]
class AffiliateProgram extends Model
{
    use ScopesToOrganizationStore, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(fn (AffiliateProgram $program) => $program->public_id ??= (string) Str::ulid());
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(AffiliateProgramRule::class, 'program_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(AffiliateProgramMembership::class, 'program_id');
    }

    protected function casts(): array
    {
        return [
            'type' => ProgramType::class, 'status' => ProgramStatus::class,
            'settings' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime',
            'attribution_window_days' => 'integer', 'hold_days' => 'integer',
            'coupon_enabled' => 'boolean', 'customer_discount_rate_basis_points' => 'integer',
            'customer_discount_amount_minor' => 'integer',
        ];
    }
}
