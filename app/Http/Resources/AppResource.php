<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $attributes = $this->resource->getAttributes();
        $hasCurrentStoreStatus = array_key_exists('current_store_installation_status', $attributes);
        $currentStoreStatus = $hasCurrentStoreStatus
            ? ($attributes['current_store_installation_status'] ?? 'not_installed')
            : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'status' => $this->status,
            'description' => $this->description,
            'current_store_installation' => $this->when($hasCurrentStoreStatus, fn (): array => [
                'status' => $currentStoreStatus,
                'is_installed' => $currentStoreStatus === 'active',
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
