<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'uuid', 'codex_api_token_id', 'user_id', 'organization_id', 'store_id', 'action',
    'idempotency_key', 'payload_hash', 'payload', 'summary', 'status', 'result',
    'error_code', 'expires_at', 'executed_at',
])]
class CodexActionConfirmation extends Model
{
    public function token(): BelongsTo
    {
        return $this->belongsTo(CodexApiToken::class, 'codex_api_token_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'expires_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }
}
