<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'codex_oauth_client_id', 'user_id', 'organization_id', 'code_hash', 'redirect_uri',
    'abilities', 'code_challenge', 'resource', 'expires_at', 'used_at',
])]
#[Hidden(['code_hash', 'code_challenge'])]
class CodexOAuthAuthorizationCode extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'codex_oauth_authorization_codes';

    public function client(): BelongsTo
    {
        return $this->belongsTo(CodexOAuthClient::class, 'codex_oauth_client_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
