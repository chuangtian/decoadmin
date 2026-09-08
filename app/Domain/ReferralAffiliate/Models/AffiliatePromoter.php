<?php

namespace App\Domain\ReferralAffiliate\Models;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable(['public_id', 'organization_id', 'email_encrypted', 'email_hash', 'display_name', 'type', 'status', 'tags', 'notes', 'created_by', 'updated_by'])]
#[Hidden(['email_hash'])]
class AffiliatePromoter extends Model
{
    use SoftDeletes;

    protected static function booted(): void
    {
        static::creating(fn (AffiliatePromoter $promoter) => $promoter->public_id ??= (string) Str::ulid());
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(AffiliateProgramMembership::class, 'promoter_id');
    }

    protected function casts(): array
    {
        return ['email_encrypted' => 'encrypted', 'profile_encrypted' => 'encrypted:array', 'tags' => 'array'];
    }
}
