<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

class StoreResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $connection = $this->whenLoaded('shopifyConnection');
        $installations = $this->whenLoaded('appInstallations');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'shopify_domain' => $this->shopify_domain,
            'status' => $this->status,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'country_code' => $this->country_code,
            'plan_name' => $this->plan_name,
            'created_at' => $this->created_at?->toIso8601String(),
            'connection' => $connection && ! $connection instanceof MissingValue ? [
                'id' => $connection->id,
                'status' => $connection->status,
                'api_version' => $connection->api_version,
                'scopes' => $connection->scopes,
                'installed_at' => $connection->installed_at?->toIso8601String(),
                'last_verified_at' => $connection->last_verified_at?->toIso8601String(),
            ] : null,
            'app_installations' => $installations && ! $installations instanceof MissingValue
                ? $installations->map(fn ($installation) => [
                    'id' => $installation->id,
                    'status' => $installation->status,
                    'installed_at' => $installation->installed_at?->toIso8601String(),
                    'app' => $installation->relationLoaded('app') && $installation->app ? [
                        'id' => $installation->app->id,
                        'name' => $installation->app->name,
                        'handle' => $installation->app->handle,
                        'status' => $installation->app->status,
                    ] : null,
                ])->values()->all()
                : [],
        ];
    }
}
