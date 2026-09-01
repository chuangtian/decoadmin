<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'uuid', 'user_id', 'organization_id', 'issued_by', 'name', 'idempotency_key', 'idempotency_hash', 'token_hash', 'abilities',
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
        $uuid = (string) Str::uuid();
        $secret = Str::random(64);
        $token = self::query()->create([
            'uuid' => $uuid,
            'user_id' => $user->getKey(),
            'organization_id' => $organization->getKey(),
            'issued_by' => $issuer?->getKey(),
            'name' => $name,
            'idempotency_key' => $idempotencyKey,
            'idempotency_hash' => $idempotencyHash,
            'token_hash' => hash('sha256', $secret),
            'abilities' => array_values(array_unique($abilities)),
            'expires_at' => now()->addDays($expiresInDays),
        ]);

        return [
            'token' => $token,
            'plain_text_token' => "dca_{$uuid}.{$secret}",
        ];
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
