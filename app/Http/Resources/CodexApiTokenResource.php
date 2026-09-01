<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CodexApiTokenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $oauthRefreshToken = $this->relationLoaded('oauthRefreshToken') ? $this->oauthRefreshToken : null;
        $connectionExpiresAt = $oauthRefreshToken && $oauthRefreshToken->isUsable()
            ? $oauthRefreshToken->expires_at
            : $this->expires_at;
        $status = $this->revoked_at !== null || ($oauthRefreshToken && $oauthRefreshToken->revoked_at !== null)
            ? 'revoked'
            : ($connectionExpiresAt->isPast() ? 'expired' : 'active');

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'source' => $this->source,
            'status' => $status,
            'abilities' => $this->abilities,
            'user' => $this->whenLoaded('user', fn (): array => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'issuer' => $this->whenLoaded('issuer', fn (): ?array => $this->issuer ? [
                'id' => $this->issuer->id,
                'name' => $this->issuer->name,
            ] : null),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'expires_at' => $connectionExpiresAt->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
