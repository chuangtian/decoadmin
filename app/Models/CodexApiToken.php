<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'uuid', 'user_id', 'organization_id', 'issued_by', 'name', 'source', 'codex_oauth_refresh_token_id',
    'idempotency_key', 'idempotency_hash', 'token_hash', 'abilities',
    'last_used_at', 'expires_at', 'revoked_at',
])]
#[Hidden(['token_hash', 'idempotency_key', 'idempotency_hash'])]
class CodexApiToken extends Model
{
    /** @var list<string> */
    public const DEFAULT_ABILITIES = [
        'stores:read',
        'dashboard:read',
        'orders:read',
        'operations:read',
        'configuration:read',
        'system:read',
    ];

    /** @var list<string> */
    public const WRITE_ABILITIES = [
        'analytics:write',
        'sync:write',
        'configuration:write',
    ];

    /** @var list<string> */
    public const SUPPORTED_ABILITIES = [
        ...self::DEFAULT_ABILITIES,
        ...self::WRITE_ABILITIES,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function oauthRefreshToken(): BelongsTo
    {
        return $this->belongsTo(CodexOAuthRefreshToken::class, 'codex_oauth_refresh_token_id');
    }

    public function allows(string $ability): bool
    {
        return in_array('*', $this->abilities, true) || in_array($ability, $this->abilities, true);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** @param list<string> $abilities
     * @return array{token: self, plain_text_token: string}
     */
    public static function issue(
        User $user,
        Organization $organization,
        string $name,
        array $abilities,
        int $expiresInDays,
        ?User $issuer = null,
        ?string $idempotencyKey = null,
        ?string $idempotencyHash = null,
    ): array {
        return self::issueUntil(
            $user,
            $organization,
            $name,
            $abilities,
            now()->addDays($expiresInDays),
            $issuer,
            $idempotencyKey,
            $idempotencyHash,
        );
    }

    /** @param list<string> $abilities
     * @return array{token: self, plain_text_token: string}
     */
    public static function issueUntil(
        User $user,
        Organization $organization,
        string $name,
        array $abilities,
        DateTimeInterface $expiresAt,
        ?User $issuer = null,
        ?string $idempotencyKey = null,
        ?string $idempotencyHash = null,
        string $source = 'manual',
        ?CodexOAuthRefreshToken $oauthRefreshToken = null,
    ): array {
        $uuid = (string) Str::uuid();
        $secret = Str::random(64);
        $token = self::query()->create([
            'uuid' => $uuid,
            'user_id' => $user->getKey(),
            'organization_id' => $organization->getKey(),
            'issued_by' => $issuer?->getKey(),
            'name' => $name,
            'source' => $source,
            'codex_oauth_refresh_token_id' => $oauthRefreshToken?->getKey(),
            'idempotency_key' => $idempotencyKey,
            'idempotency_hash' => $idempotencyHash,
            'token_hash' => hash('sha256', $secret),
            'abilities' => array_values(array_unique($abilities)),
            'expires_at' => $expiresAt,
        ]);

        return [
            'token' => $token,
            'plain_text_token' => "dca_{$uuid}.{$secret}",
        ];
    }

    public static function fromPlainText(?string $plainTextToken): ?self
    {
        if (! is_string($plainTextToken) || ! str_starts_with($plainTextToken, 'dca_')) {
            return null;
        }

        $parts = explode('.', substr($plainTextToken, 4), 2);
        if (count($parts) !== 2 || ! preg_match('/^[0-9a-f-]{36}$/i', $parts[0]) || strlen($parts[1]) < 40) {
            return null;
        }

        $token = self::query()->where('uuid', $parts[0])->first();
        if (! $token || ! hash_equals($token->token_hash, hash('sha256', $parts[1]))) {
            return null;
        }

        return $token;
    }

    /** @return array{token: self, plain_text_token: string} */
    public static function issueOrRotateOAuth(
        CodexOAuthRefreshToken $refreshToken,
        DateTimeInterface $expiresAt,
    ): array {
        $token = self::query()
            ->where('codex_oauth_refresh_token_id', $refreshToken->getKey())
            ->lockForUpdate()
            ->first();
        if (! $token) {
            return self::issueUntil(
                $refreshToken->user,
                $refreshToken->organization,
                'DecoAdmin OAuth · '.Str::limit($refreshToken->client->client_name, 80, ''),
                $refreshToken->abilities,
                $expiresAt,
                $refreshToken->user,
                source: 'oauth',
                oauthRefreshToken: $refreshToken,
            );
        }

        $uuid = (string) Str::uuid();
        $secret = Str::random(64);
        $token->forceFill([
            'uuid' => $uuid,
            'token_hash' => hash('sha256', $secret),
            'abilities' => $refreshToken->abilities,
            'last_used_at' => null,
            'expires_at' => $expiresAt,
            'revoked_at' => null,
        ])->save();

        return ['token' => $token, 'plain_text_token' => "dca_{$uuid}.{$secret}"];
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
