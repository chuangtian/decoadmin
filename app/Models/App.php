<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['organization_id', 'name', 'handle', 'client_id', 'client_secret_encrypted', 'distribution', 'status', 'scopes', 'redirect_uris', 'webhook_api_version', 'settings'])]
class App extends Model
{
    use SoftDeletes;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected function casts(): array
    {
        return [
            'client_secret_encrypted' => 'encrypted',
            'scopes' => 'array',
            'redirect_uris' => 'array',
            'settings' => 'array',
        ];
    }
}
