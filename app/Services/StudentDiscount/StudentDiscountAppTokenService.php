<?php

namespace App\Services\StudentDiscount;

use App\Exceptions\StudentDiscountException;
use App\Models\AppInstallation;
use App\Models\Store;
use Illuminate\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;

class StudentDiscountAppTokenService
{
    private const REFRESH_LEEWAY_SECONDS = 120;

    public function __construct(private HttpFactory $http) {}

    public function accessTokenFor(Store $store): string
    {
        $installation = $this->installationFor($store);
        $this->assertUsableInstallation($installation);

        if ($this->hasFreshAccessToken($installation)) {
            return (string) $installation->access_token_encrypted;
        }

        try {
            return Cache::lock("student-discount-app-token:{$installation->id}", 30)
                ->block(5, function () use ($store): string {
                    $lockedInstallation = $this->installationFor($store);
                    $this->assertUsableInstallation($lockedInstallation);

                    if ($this->hasFreshAccessToken($lockedInstallation)) {
                        return (string) $lockedInstallation->access_token_encrypted;
                    }

                    return $this->refresh($lockedInstallation);
                });
        } catch (LockTimeoutException) {
            throw new StudentDiscountException(
                'STUDENT_DISCOUNT_TOKEN_REFRESH_BUSY',
                '学生优惠授权正在刷新，请稍后重试。',
                503,
            );
        }
    }

    private function installationFor(Store $store): AppInstallation
    {
        $handle = trim((string) config('student_discount.active.handle'));
        $clientId = trim((string) config('student_discount.active.client_id'));
        $storeIsActive = Store::query()
            ->whereKey($store->id)
            ->where('organization_id', $store->organization_id)
            ->where('status', 'active')
            ->whereHas('organization', fn ($query) => $query->where('status', 'active'))
            ->exists();
        if (! $storeIsActive || $handle === '' || $clientId === '') {
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
            ->firstOr(function (): never {
                throw $this->reauthorizationRequired();
            });
    }

    private function assertUsableInstallation(AppInstallation $installation): void
    {
        $grantedScopes = is_array($installation->granted_scopes) ? $installation->granted_scopes : [];

        if (! $this->hasRequiredScopes($grantedScopes)
            || $installation->token_type !== 'offline'
            || ! is_string($installation->access_token_encrypted)
            || $installation->access_token_encrypted === '') {
            throw $this->reauthorizationRequired();
        }
    }

    private function hasFreshAccessToken(AppInstallation $installation): bool
    {
        return $installation->access_token_expires_at !== null
            && $installation->access_token_expires_at->isAfter(now()->addSeconds(self::REFRESH_LEEWAY_SECONDS));
    }

    private function refresh(AppInstallation $installation): string
    {
        $refreshToken = $installation->refresh_token_encrypted;
        if (! is_string($refreshToken)
            || $refreshToken === ''
            || $installation->refresh_token_expires_at === null
            || $installation->refresh_token_expires_at->isPast()) {
            throw $this->reauthorizationRequired();
        }

        $shop = $installation->store()->value('shopify_domain');
        $clientId = trim((string) config('student_discount.active.client_id'));
        $clientSecret = (string) config('student_discount.active.client_secret');
        if (! is_string($shop) || $shop === '' || $clientId === '' || $clientSecret === '') {
            throw new StudentDiscountException(
                'STUDENT_DISCOUNT_APP_NOT_CONFIGURED',
                '学生优惠 App 当前环境尚未配置。',
                503,
            );
        }

        try {
            $response = $this->http
                ->asForm()
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->post("https://{$shop}/admin/oauth/access_token", [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ]);
        } catch (ConnectionException) {
            throw new StudentDiscountException(
                'STUDENT_DISCOUNT_TOKEN_REFRESH_TIMEOUT',
                'Shopify 授权刷新超时，请稍后重试。',
                502,
            );
        }

        if (in_array($response->status(), [400, 401], true)) {
            throw $this->reauthorizationRequired();
        }

        $accessToken = $response->json('access_token');
        $newRefreshToken = $response->json('refresh_token');
        $expiresIn = $response->json('expires_in');
        $refreshTokenExpiresIn = $response->json('refresh_token_expires_in');
        if ($response->failed()
            || ! is_string($accessToken) || $accessToken === ''
            || ! is_string($newRefreshToken) || $newRefreshToken === ''
            || ! is_numeric($expiresIn) || (int) $expiresIn <= 0
            || ! is_numeric($refreshTokenExpiresIn) || (int) $refreshTokenExpiresIn <= 0) {
            throw new StudentDiscountException(
                'STUDENT_DISCOUNT_TOKEN_REFRESH_FAILED',
                'Shopify 授权刷新失败，请稍后重试。',
                502,
            );
        }

        $scopeValue = $response->json('scope');
        $grantedScopes = is_string($scopeValue) && $scopeValue !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $scopeValue))))
            : (array) $installation->granted_scopes;
        sort($grantedScopes);
        if (! $this->hasRequiredScopes($grantedScopes)) {
            throw $this->reauthorizationRequired();
        }

        $issuedAt = now();
        $installation->forceFill([
            'access_token_encrypted' => $accessToken,
            'refresh_token_encrypted' => $newRefreshToken,
            'token_type' => 'offline',
            'granted_scopes' => $grantedScopes,
            'access_token_expires_at' => $issuedAt->copy()->addSeconds((int) $expiresIn),
            'refresh_token_expires_at' => $issuedAt->copy()->addSeconds((int) $refreshTokenExpiresIn),
        ])->save();
        $this->assertUsableInstallation($installation);

        return $accessToken;
    }

    /** @param list<string> $grantedScopes */
    private function scopeIsGranted(string $required, array $grantedScopes): bool
    {
        return in_array($required, $grantedScopes, true)
            || (str_starts_with($required, 'read_')
                && in_array('write_'.substr($required, 5), $grantedScopes, true));
    }

    /** @param list<string> $grantedScopes */
    private function hasRequiredScopes(array $grantedScopes): bool
    {
        return collect(config('student_discount.required_scopes', []))->doesntContain(
            fn (mixed $required): bool => ! is_string($required)
                || ! $this->scopeIsGranted($required, $grantedScopes),
        );
    }

    private function reauthorizationRequired(): StudentDiscountException
    {
        return new StudentDiscountException(
            'STUDENT_DISCOUNT_APP_REAUTH_REQUIRED',
            '请在 Shopify 后台重新打开学生优惠 App 完成授权。',
            409,
        );
    }
}
