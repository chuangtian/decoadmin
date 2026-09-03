<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'client_id', 'client_name', 'redirect_uris', 'grant_types', 'response_types',
    'token_endpoint_auth_method', 'registration_fingerprint',
])]
class CodexOAuthClient extends Model
{
    protected $table = 'codex_oauth_clients';

    public function authorizationCodes(): HasMany
    {
        return $this->hasMany(CodexOAuthAuthorizationCode::class, 'codex_oauth_client_id');
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(CodexOAuthRefreshToken::class, 'codex_oauth_client_id');
    }

    public function acceptsRedirectUri(string $redirectUri): bool
    {
        return in_array($redirectUri, $this->redirect_uris, true);
    }

    protected function casts(): array
    {
        return [
            'redirect_uris' => 'array',
            'grant_types' => 'array',
            'response_types' => 'array',
        ];
    }
}
