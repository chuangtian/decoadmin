<?php

namespace App\Services\InstagramFeed;

use JsonException;

/**
 * Meta 的 deauthorize / data deletion 回调会 POST 一个 signed_request，
 * 格式是 base64url(签名).base64url(载荷)，用应用密钥做 HMAC-SHA256 验签。
 * https://developers.facebook.com/docs/reference/login/signed-request
 *
 * 两条授权路线用的密钥不同，逐个试；全部不匹配时返回 null，调用方一律当作
 * 无效请求处理，不回显任何信息。
 *
 * 凭证按店铺存之后，回调里没有任何店铺线索（signed_request 只带 Meta 用户 ID），
 * 所以候选密钥 = 平台级两条 + 所有店铺自己配的那些。命中后由
 * InstagramAccountService::purgeByMetaUser() 按 Meta 用户 ID 找出真正受影响的店铺。
 */
class MetaSignedRequestValidator
{
    public function __construct(private InstagramFeedStoreCredentials $credentials) {}

    /** @return array{user_id: string, algorithm: string|null, issued_at: int|null}|null */
    public function parse(?string $signedRequest): ?array
    {
        if (! is_string($signedRequest) || $signedRequest === '') {
            return null;
        }

        $segments = explode('.', $signedRequest);
        if (count($segments) !== 2 || $segments[0] === '' || $segments[1] === '') {
            return null;
        }
        [$encodedSignature, $encodedPayload] = $segments;

        $signature = $this->base64UrlDecode($encodedSignature);
        if ($signature === null) {
            return null;
        }

        foreach ($this->candidateSecrets() as $secret) {
            if (hash_equals(hash_hmac('sha256', $encodedPayload, $secret, true), $signature)) {
                return $this->decodePayload($encodedPayload);
            }
        }

        return null;
    }

    /** @return array{user_id: string, algorithm: string|null, issued_at: int|null}|null */
    private function decodePayload(string $encodedPayload): ?array
    {
        $decoded = $this->base64UrlDecode($encodedPayload);
        if ($decoded === null) {
            return null;
        }

        try {
            $payload = json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($payload)) {
            return null;
        }

        $userId = $payload['user_id'] ?? null;
        if (! is_scalar($userId) || (string) $userId === '' || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $userId)) {
            return null;
        }

        return [
            'user_id' => (string) $userId,
            'algorithm' => is_string($payload['algorithm'] ?? null) ? $payload['algorithm'] : null,
            'issued_at' => is_numeric($payload['issued_at'] ?? null) ? (int) $payload['issued_at'] : null,
        ];
    }

    /** @return list<string> */
    private function candidateSecrets(): array
    {
        $secrets = array_filter([
            (string) config('instagram_feed.instagram.app_secret'),
            (string) config('instagram_feed.facebook.app_secret'),
        ], fn (string $secret): bool => $secret !== '');

        return array_values(array_unique([...$secrets, ...$this->credentials->allMetaAppSecrets()]));
    }

    private function base64UrlDecode(string $value): ?string
    {
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value.str_repeat('=', $padding), '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }
}
