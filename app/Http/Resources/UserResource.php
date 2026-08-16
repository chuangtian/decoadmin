<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'avatar_url' => $this->avatar_url,
            'roles' => RoleResource::collection($this->whenLoaded('roles')),
            'stores' => $this->whenLoaded('stores', fn () => $this->stores->map(fn ($store) => [
                'id' => $store->id,
                'name' => $store->name,
                'status' => $store->status,
            ])->values()),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
