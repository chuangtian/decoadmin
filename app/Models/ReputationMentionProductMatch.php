<?php

namespace App\Models;

use App\Models\Concerns\ScopesToOrganizationStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'store_id', 'reputation_mention_id', 'product_id',
    'match_role', 'matched_alias', 'confidence',
])]
class ReputationMentionProductMatch extends Model
{
    use ScopesToOrganizationStore;

    public function mention(): BelongsTo
    {
        return $this->belongsTo(ReputationMention::class, 'reputation_mention_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
        ];
    }
}
