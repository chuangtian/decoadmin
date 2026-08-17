<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppInstallationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'installed_at' => $this->installed_at?->toIso8601String(),
            'uninstalled_at' => $this->uninstalled_at?->toIso8601String(),
            'store' => $this->whenLoaded('store', fn (): array => [
                'id' => $this->store->id,
                'name' => $this->store->name,
                'shopify_domain' => $this->store->shopify_domain,
                'status' => $this->store->status,
            ]),
        ];
    }
}
