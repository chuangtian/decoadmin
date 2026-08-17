<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class WebhookEventDetailResource extends WebhookEventResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'api_version' => $this->api_version,
            'headers' => $this->headers ?? [],
            'payload' => $this->decodedPayload(),
            'payload_integrity_valid' => $this->payloadIntegrityIsValid(),
            'last_error' => $this->last_error,
            'next_retry_at' => $this->next_retry_at?->toIso8601String(),
            'app' => $this->whenLoaded('app', fn () => $this->app ? [
                'id' => $this->app->id,
                'name' => $this->app->name,
                'slug' => $this->app->handle,
            ] : null),
        ];
    }
}
