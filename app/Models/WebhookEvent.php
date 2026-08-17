<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'webhook_id', 'organization_id', 'store_id', 'shopify_connection_id', 'app_id',
    'topic', 'api_version', 'headers', 'payload', 'payload_encrypted', 'payload_sha256',
    'status', 'attempts', 'received_at', 'next_retry_at', 'processed_at', 'last_error',
])]
#[Hidden(['payload_encrypted'])]
class WebhookEvent extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function shopifyConnection(): BelongsTo
    {
        return $this->belongsTo(ShopifyConnection::class);
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function rawPayload(): string
    {
        if (is_string($this->payload_encrypted) && $this->payload_encrypted !== '') {
            return $this->payload_encrypted;
        }

        return json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /** @return array<string, mixed>|list<mixed>|null */
    public function decodedPayload(): ?array
    {
        $payload = json_decode($this->rawPayload(), true);

        return is_array($payload) ? $payload : null;
    }

    public function payloadIntegrityIsValid(): bool
    {
        return ! $this->payload_sha256
            || hash_equals($this->payload_sha256, hash('sha256', $this->rawPayload()));
    }

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'payload' => 'array',
            'payload_encrypted' => 'encrypted',
            'attempts' => 'integer',
            'received_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
