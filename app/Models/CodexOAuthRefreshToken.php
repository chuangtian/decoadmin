<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'uuid', 'codex_oauth_client_id', 'user_id', 'organization_id', 'token_hash',
    'abilities', 'resource', 'last_used_at', 'expires_at', 'revoked_at',
])]
#[Hidden(['token_hash'])]
class CodexOAuthRefreshToken extends Model
{
    protected $table = 'codex_oauth_refresh_tokens';

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

    public function accessTokens(): HasMany
    {
        return $this->hasMany(CodexApiToken::class, 'codex_oauth_refresh_token_id');
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** @return array{token: self, plain_text_token: string} */
    public static function issue(
        CodexOAuthClient $client,
        User $user,
        Organization $organization,
        array $abilities,
        string $resource,
    ): array {
        $uuid = (string) Str::uuid();
        $secret = Str::random(64);
        $token = self::query()->create([
            'uuid' => $uuid,
            'codex_oauth_client_id' => $client->getKey(),
            'user_id' => $user->getKey(),
            'organization_id' => $organization->getKey(),
            'token_hash' => hash('sha256', $secret),
            'abilities' => $abilities,
            'resource' => $resource,
            'expires_at' => now()->addDays((int) config('codex.refresh_token_ttl_days', 30)),
        ]);

        return ['token' => $token, 'plain_text_token' => "dcrf_{$uuid}.{$secret}"];
    }

    public static function fromPlainText(?string $plainTextToken): ?self
    {
        if (! is_string($plainTextToken) || ! str_starts_with($plainTextToken, 'dcrf_')) {
            return null;
        }

        $parts = explode('.', substr($plainTextToken, 5), 2);
        if (count($parts) !== 2 || ! preg_match('/^[0-9a-f-]{36}$/i', $parts[0]) || strlen($parts[1]) < 40) {
            return null;
        }

        $token = self::query()->where('uuid', $parts[0])->first();
        if (! $token || ! hash_equals($token->token_hash, hash('sha256', $parts[1]))) {
            return null;
        }

        return $token;
    }

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
