<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StorefrontEvent;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use JsonException;

class StorefrontEventIngestionService
{
    /** @var list<string> */
    public const EVENTS = [
        'page_viewed',
        'product_viewed',
        'product_added_to_cart',
        'product_removed_from_cart',
        'cart_viewed',
        'search_submitted',
        'checkout_started',
        'checkout_contact_info_submitted',
        'checkout_address_info_submitted',
        'checkout_shipping_info_submitted',
        'payment_info_submitted',
        'checkout_completed',
    ];

    /** @return array{event: StorefrontEvent, created: bool} */
    public function ingest(Store $store, string $rawPayload, array $requestContext = []): array
    {
        if (strlen($rawPayload) > 64 * 1024) {
            throw new InvalidArgumentException('事件载荷超过 64 KB 限制。');
        }

        try {
            $payload = json_decode($rawPayload, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('事件载荷不是有效 JSON。');
        }

        if (! is_array($payload)) {
            throw new InvalidArgumentException('事件载荷格式无效。');
        }

        $eventId = $this->requiredString($payload, 'event_id', 191);
        $eventName = $this->requiredString($payload, 'event_name', 80);

        if (! in_array($eventName, self::EVENTS, true)) {
            throw new InvalidArgumentException('不支持该客户事件。');
        }

        $clientId = $this->requiredString($payload, 'client_id', 512);
        $sessionId = $this->requiredString($payload, 'session_id', 512);
        $occurredAt = $this->timestamp($payload['occurred_at'] ?? null);
        $now = CarbonImmutable::now('UTC');

        if ($occurredAt->lt($now->subDays(7)) || $occurredAt->gt($now->addMinutes(5))) {
            throw new InvalidArgumentException('客户事件时间超出允许范围。');
        }

        $event = StorefrontEvent::query()->firstOrCreate([
            'store_id' => $store->getKey(),
            'event_id' => $eventId,
        ], [
            'organization_id' => $store->organization_id,
            'event_name' => $eventName,
            'client_id_hash' => $this->identifierHash($clientId),
            'session_id_hash' => $this->identifierHash($sessionId),
            'occurred_at' => $occurredAt,
            'path' => $this->path($payload['path'] ?? null),
            'referrer_host' => $this->host($payload['referrer_host'] ?? null),
            'country_code' => $this->countryCode($payload['country_code'] ?? ($requestContext['country_code'] ?? null)),
            'region_code' => $this->locationPart($payload['region_code'] ?? null, 64),
            'city' => $this->locationPart($payload['city'] ?? null, 120),
            'search_query' => $eventName === 'search_submitted'
                ? $this->searchQuery($payload['search_query'] ?? null)
                : null,
            'received_at' => $now,
        ]);

        return ['event' => $event, 'created' => $event->wasRecentlyCreated];
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $key, int $maxLength): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException("客户事件字段 [{$key}] 无效。");
        }

        return trim($value);
    }

    private function timestamp(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException('客户事件时间无效。');
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            throw new InvalidArgumentException('客户事件时间无效。');
        }
    }

    private function identifierHash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function path(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $path = parse_url($value, PHP_URL_PATH);

        return is_string($path) && str_starts_with($path, '/')
            ? mb_substr($path, 0, 512)
            : null;
    }

    private function host(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $host = strtolower(trim($value));

        return preg_match('/^[a-z0-9.-]+$/', $host) === 1
            ? mb_substr($host, 0, 255)
            : null;
    }

    private function searchQuery(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $query = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        $query = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', '[已脱敏]', $query) ?? $query;
        $query = preg_replace('/(?<!\d)(?:\+?\d[\d\s().-]{7,}\d)(?!\d)/u', '[已脱敏]', $query) ?? $query;

        return $query === '' ? null : mb_substr($query, 0, 160);
    }

    private function countryCode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $country = strtoupper(trim($value));

        return preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;
    }

    private function locationPart(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }
}
