<?php

namespace App\Domain\ReferralAffiliate\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use App\Models\Organization;
use App\Models\Store;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'store_id', 'program_id', 'scope', 'scope_reference', 'commission_type', 'rate_basis_points', 'amount_minor', 'priority', 'enabled', 'settings'])]
class AffiliateProgramRule extends Model
{
    use ScopesToOrganizationStore;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(AffiliateProgram::class, 'program_id');
    }

    protected function casts(): array
    {
        return ['rate_basis_points' => 'integer', 'amount_minor' => 'integer', 'priority' => 'integer', 'enabled' => 'boolean', 'settings' => 'array'];
    }
}
