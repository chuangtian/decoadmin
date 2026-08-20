<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SyncJobResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'type' => $this->type,
            'mode' => $this->mode,
            'status' => $this->status,
            'since_at' => $this->since_at?->toIso8601String(),
            'until_at' => $this->until_at?->toIso8601String(),
            'error_code' => $this->error_code,
            'correlation_id' => $this->correlation_id,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'store' => $this->whenLoaded('store', fn () => [
                'id' => $this->store->id,
                'name' => $this->store->name,
                'shopify_domain' => $this->store->shopify_domain,
                'timezone' => $this->store->timezone ?: 'UTC',
            ]),
            'app_installation' => $this->whenLoaded('appInstallation', fn () => $this->appInstallation ? [
                'id' => $this->appInstallation->id,
                'status' => $this->appInstallation->status,
                'app' => $this->appInstallation->relationLoaded('app') && $this->appInstallation->app ? [
                    'id' => $this->appInstallation->app->id,
                    'name' => $this->appInstallation->app->name,
                    'handle' => $this->appInstallation->app->handle,
                ] : null,
            ] : null),
        ];
    }
}
