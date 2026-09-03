<?php

namespace App\Services\Discounts;

use App\Exceptions\DiscountManagerException;
use App\Models\AppInstallation;
use App\Models\Store;
use Illuminate\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;

class DiscountManagerAppTokenService
{
    private const REFRESH_LEEWAY_SECONDS = 120;

    public function __construct(
        private HttpFactory $http,
        private DiscountManagerShopGuard $shopGuard,
    ) {}

    public function accessTokenFor(Store $store): string
    {
        $this->shopGuard->assertAllowed((string) $store->shopify_domain);
        $installation = $this->installationFor($store);
        $this->assertUsable($installation);
        if ($this->isFresh($installation)) {
            return (string) $installation->access_token_encrypted;
        }

        try {
            return Cache::lock("discount-manager-token:{$installation->id}", 30)
                ->block(5, function () use ($store): string {
                    $installation = $this->installationFor($store);
                    $this->assertUsable($installation);

                    return $this->isFresh($installation)
                        ? (string) $installation->access_token_encrypted
                        : $this->refresh($installation);
                });
        } catch (LockTimeoutException) {
            throw new DiscountManagerException('DISCOUNT_MANAGER_TOKEN_REFRESH_BUSY', '折扣管理授权正在刷新，请稍后重试。', 503);
        }
    }

    private function installationFor(Store $store): AppInstallation
    {
        $handle = trim((string) config('discount_manager.active.handle'));
        $clientId = trim((string) config('discount_manager.active.client_id'));
        if ($handle === '' || $clientId === '' || $store->status !== 'active') {
            throw $this->reauthorizationRequired();
        }

        return AppInstallation::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->whereHas('app', fn ($query) => $query
                ->whereNull('organization_id')
                ->where('handle', $handle)
                ->where('client_id', $clientId)
                ->where('status', 'active'))
            ->firstOr(fn (): never => throw $this->reauthorizationRequired());
    }

    private function assertUsable(AppInstallation $installation): void
    {
        $granted = is_array($installation->granted_scopes) ? $installation->granted_scopes : [];
        $missing = collect((array) config('discount_manager.required_scopes', []))
            ->contains(fn (mixed $scope): bool => is_string($scope) && ! $this->scopeIsGranted($scope, $granted));
        if ($missing || $installation->token_type !== 'offline'
            || ! is_string($installation->access_token_encrypted)
            || $installation->access_token_encrypted === '') {
            throw $this->reauthorizationRequired();
        }
    }

    private function isFresh(AppInstallation $installation): bool
    {
        return $installation->access_token_expires_at === null
            || $installation->access_token_expires_at->isAfter(now()->addSeconds(self::REFRESH_LEEWAY_SECONDS));
    }

    private function refresh(AppInstallation $installation): string
    {
        $shop = $this->shopGuard->assertAllowed((string) $installation->store()->value('shopify_domain'));
        $refreshToken = $installation->refresh_token_encrypted;
        $clientId = trim((string) config('discount_manager.active.client_id'));
        $clientSecret = (string) config('discount_manager.active.client_secret');
        if (! is_string($refreshToken) || $refreshToken === ''
            || $installation->refresh_token_expires_at?->isPast() !== false
            || $clientId === '' || $clientSecret === '') {
            throw $this->reauthorizationRequired();
        }

        try {
            $response = $this->http->asForm()->acceptJson()->connectTimeout(5)->timeout(20)
                ->post("https://{$shop}/admin/oauth/access_token", [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ]);
        } catch (ConnectionException) {
            throw new DiscountManagerException('SHOPIFY_TOKEN_REFRESH_TIMEOUT', 'Shopify 授权刷新超时，请稍后重试。', 502);
        }

        $accessToken = $response->json('access_token');
        $newRefreshToken = $response->json('refresh_token');
        $expiresIn = $response->json('expires_in');
        $refreshExpiresIn = $response->json('refresh_token_expires_in');
        if ($response->failed() || ! is_string($accessToken) || $accessToken === ''
            || ! is_string($newRefreshToken) || $newRefreshToken === ''
            || ! is_numeric($expiresIn) || ! is_numeric($refreshExpiresIn)) {
            throw $this->reauthorizationRequired();
        }

        $issuedAt = now();
        $scopes = array_values(array_filter(array_map('trim', explode(',', (string) $response->json('scope', '')))));
        $installation->forceFill([
            'access_token_encrypted' => $accessToken,
            'refresh_token_encrypted' => $newRefreshToken,
            'granted_scopes' => $scopes ?: $installation->granted_scopes,
            'access_token_expires_at' => $issuedAt->copy()->addSeconds((int) $expiresIn),
            'refresh_token_expires_at' => $issuedAt->copy()->addSeconds((int) $refreshExpiresIn),
        ])->save();
        $this->assertUsable($installation);

        return $accessToken;
    }

    /** @param list<string> $granted */
    private function scopeIsGranted(string $required, array $granted): bool
    {
        return in_array($required, $granted, true)
            || (str_starts_with($required, 'read_') && in_array('write_'.substr($required, 5), $granted, true));
    }

    private function reauthorizationRequired(): DiscountManagerException
    {
        return new DiscountManagerException(
            'DISCOUNT_MANAGER_APP_REAUTH_REQUIRED',
            '请在 Shopify 后台打开 Deco 折扣管理并完成授权。',
            409,
        );
    }
}
