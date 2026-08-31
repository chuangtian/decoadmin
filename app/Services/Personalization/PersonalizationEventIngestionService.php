<?php

namespace App\Services\Personalization;

use App\Enums\PersonalizationComponentStatus;
use App\Enums\PersonalizationPlacement;
use App\Exceptions\PersonalizationException;
use App\Models\PersonalizationEvent;
use App\Models\PersonalizationEventProduct;
use App\Models\PersonalizationEventSource;
use App\Models\PersonalizationRecommendationComponent;
use App\Models\PersonalizationSmartCartSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

class PersonalizationEventIngestionService
{
    public const IMPRESSION = 'deco_personalization:impression';

    public const CLICK = 'deco_personalization:click';

    public const ADD_TO_CART = 'deco_personalization:add_to_cart';

    public const ADD_SUCCESS = 'deco_personalization:add_success';

    public const ADD_FAILED = 'deco_personalization:add_failed';

    public const CHECKOUT_RECOMMENDATION_IMPRESSION = 'deco_personalization:checkout_recommendation_impression';

    public const CHECKOUT_RECOMMENDATION_CLICK = 'deco_personalization:checkout_recommendation_click';

    public const CHECKOUT_RECOMMENDATION_ADD_SUCCESS = 'deco_personalization:checkout_recommendation_add_success';

    public const CHECKOUT_RECOMMENDATION_ADD_FAILED = 'deco_personalization:checkout_recommendation_add_failed';

    public const CHECKOUT_RECOMMENDATION_SEQUENCE_COMPLETED = 'deco_personalization:checkout_recommendation_sequence_completed';

    public const CHECKOUT_COMPLETED = 'checkout_completed';

    private const EVENTS = [
        self::IMPRESSION,
        self::CLICK,
        self::ADD_TO_CART,
        self::ADD_SUCCESS,
        self::ADD_FAILED,
        self::CHECKOUT_RECOMMENDATION_IMPRESSION,
        self::CHECKOUT_RECOMMENDATION_CLICK,
        self::CHECKOUT_RECOMMENDATION_ADD_SUCCESS,
        self::CHECKOUT_RECOMMENDATION_ADD_FAILED,
        self::CHECKOUT_RECOMMENDATION_SEQUENCE_COMPLETED,
        self::CHECKOUT_COMPLETED,
    ];

    public function __construct(private PersonalizationShopGuard $shopGuard) {}

    /** @return array{event: PersonalizationEvent, created: bool} */
    public function ingest(PersonalizationEventSource $source, string $rawPayload): array
    {
        if (strlen($rawPayload) > 32 * 1024) {
            throw new PersonalizationException('PERSONALIZATION_EVENT_TOO_LARGE', '推荐事件超过允许大小。', 413);
        }

        try {
            $payload = json_decode($rawPayload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PersonalizationException('INVALID_PERSONALIZATION_EVENT', '推荐事件不是有效 JSON。');
        }
        if (! is_array($payload) || array_is_list($payload)) {
            throw new PersonalizationException('INVALID_PERSONALIZATION_EVENT', '推荐事件格式无效。');
        }

        $store = $source->store()->with('organization')->first();
        if (! $store instanceof Store
            || (int) $source->organization_id !== (int) $store->organization_id
            || $source->status !== 'active'
            || $store->status !== 'active'
            || ! $store->organization
            || $store->organization->status !== 'active') {
            throw new PersonalizationException('PERSONALIZATION_EVENT_SOURCE_INACTIVE', '推荐事件来源未启用。', 404);
        }
        $this->shopGuard->assertAllowed((string) $store->shopify_domain);

        $eventId = $this->requiredString($payload, 'event_id', 191);
        $eventName = $this->requiredString($payload, 'event_name', 64);
        if (! in_array($eventName, self::EVENTS, true)) {
            throw new PersonalizationException('UNSUPPORTED_PERSONALIZATION_EVENT', '不支持该推荐事件。');
        }
        $clientHash = $this->identifierHash($this->requiredString($payload, 'client_id', 512));
        $sessionHash = $this->identifierHash($this->requiredString($payload, 'session_id', 512));
        $occurredAt = $this->timestamp($payload['occurred_at'] ?? null);
        $now = CarbonImmutable::now('UTC');
        if ($occurredAt->lt($now->subDays(7)) || $occurredAt->gt($now->addMinutes(5))) {
            throw new PersonalizationException('PERSONALIZATION_EVENT_TIME_INVALID', '推荐事件时间超出允许范围。');
        }

        $context = $eventName === self::CHECKOUT_COMPLETED
            ? $this->checkoutContext($payload)
            : $this->recommendationContext($store, $eventName, $payload);
        $payloadHash = hash('sha256', json_encode([
            'event_name' => $eventName,
            'occurred_at' => $occurredAt->toIso8601String(),
            ...$context,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($source, $store, $eventId, $eventName, $clientHash, $sessionHash, $payloadHash, $occurredAt, $now, $context): array {
            $existing = PersonalizationEvent::query()
                ->where('store_id', $store->id)
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if ($existing->event_name !== $eventName
                    || ! hash_equals($existing->client_id_hash, $clientHash)
                    || ! hash_equals($existing->session_id_hash, $sessionHash)
                    || ! hash_equals($existing->payload_hash, $payloadHash)) {
                    throw new PersonalizationException(
                        'PERSONALIZATION_EVENT_ID_CONFLICT',
                        '推荐事件 ID 与已接收事件冲突。',
                        409,
                    );
                }

                return ['event' => $existing, 'created' => false];
            }

            $event = PersonalizationEvent::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'event_source_id' => $source->id,
                'event_id' => $eventId,
                'event_name' => $eventName,
                'client_id_hash' => $clientHash,
                'session_id_hash' => $sessionHash,
                'payload_hash' => $payloadHash,
                'component_id' => $context['component_id'],
                'strategy_id' => $context['strategy_id'],
                'strategy_version_id' => $context['strategy_version_id'],
                'placement' => $context['placement'],
                'shopify_order_id' => $context['shopify_order_id'],
                'occurred_at' => $occurredAt,
                'received_at' => $now,
            ]);
            foreach ($context['products'] as $product) {
                PersonalizationEventProduct::query()->create([
                    'event_id' => $event->id,
                    ...$product,
                ]);
            }
            $source->forceFill(['last_event_at' => $now])->save();

            return ['event' => $event->load('products'), 'created' => true];
        });
    }

    /** @param array<string, mixed> $payload @return array{component_id: ?int, strategy_id: ?int, strategy_version_id: ?int, placement: ?string, shopify_order_id: ?string, products: list<array<string, mixed>>} */
    private function recommendationContext(Store $store, string $eventName, array $payload): array
    {
        $placement = PersonalizationPlacement::tryFrom((string) ($payload['placement'] ?? ''));
        $strategyUuid = trim((string) ($payload['strategy_uuid'] ?? ''));
        $componentUuid = trim((string) ($payload['component_uuid'] ?? ''));
        if (! $placement || ! Str::isUuid($strategyUuid)) {
            throw new PersonalizationException('INVALID_PERSONALIZATION_EVENT_CONTEXT', '推荐事件上下文无效。');
        }

        $componentId = null;
        $strategyId = null;
        $strategyVersionId = null;
        $setting = null;
        $component = null;
        if ($placement === PersonalizationPlacement::SmartCart && $componentUuid === '') {
            $setting = PersonalizationSmartCartSetting::query()
                ->where('organization_id', $store->organization_id)
                ->where('store_id', $store->id)
                ->where('enabled', true)
                ->where('fallback_mode', 'native_cart')
                ->whereHas('strategy', fn ($query) => $query->where('uuid', $strategyUuid)->where('enabled', true))
                ->with('strategy.publishedVersion')
                ->first();
            if (! $setting || ! $setting->strategy) {
                throw new PersonalizationException('INVALID_PERSONALIZATION_EVENT_CONTEXT', 'Smart Cart 推荐上下文未启用。', 409);
            }
            $strategyId = $setting->strategy->id;
            $strategyVersionId = $setting->strategy->published_version_id;
        } else {
            if (! Str::isUuid($componentUuid)) {
                throw new PersonalizationException('INVALID_PERSONALIZATION_EVENT_CONTEXT', '推荐组件标识无效。');
            }
            $component = PersonalizationRecommendationComponent::query()
                ->where('organization_id', $store->organization_id)
                ->where('store_id', $store->id)
                ->where('uuid', $componentUuid)
                ->where('status', PersonalizationComponentStatus::Active->value)
                ->where('placement', $placement->value)
                ->whereHas('strategy', fn ($query) => $query->where('uuid', $strategyUuid)->where('enabled', true))
                ->with(['strategy.publishedVersion', 'strategyVersion'])
                ->first();
            if (! $component || ! $component->strategy) {
                throw new PersonalizationException('INVALID_PERSONALIZATION_EVENT_CONTEXT', '推荐组件上下文未启用。', 409);
            }
            $componentId = $component->id;
            $strategyId = $component->strategy->id;
            $strategyVersionId = $component->strategy_version_id ?: $component->strategy->published_version_id;
        }
        $reportedVersionUuid = trim((string) ($payload['strategy_version_uuid'] ?? ''));
        $resolvedVersion = $placement === PersonalizationPlacement::SmartCart
            ? $setting?->strategy?->publishedVersion
            : ($component?->strategyVersion ?: $component?->strategy?->publishedVersion);
        if ($reportedVersionUuid !== ''
            && (! Str::isUuid($reportedVersionUuid) || $reportedVersionUuid !== $resolvedVersion?->uuid)) {
            throw new PersonalizationException('INVALID_PERSONALIZATION_EVENT_CONTEXT', '推荐事件策略版本与当前组件不一致。', 409);
        }

        return [
            'component_id' => $componentId,
            'strategy_id' => $strategyId,
            'strategy_version_id' => $strategyVersionId,
            'placement' => $placement->value,
            'shopify_order_id' => null,
            'products' => $this->products($store, $eventName, $payload['products'] ?? null),
        ];
    }

    /** @param array<string, mixed> $payload @return array{component_id: null, strategy_id: null, strategy_version_id: null, placement: null, shopify_order_id: string, products: array{}} */
    private function checkoutContext(array $payload): array
    {
        $orderId = $this->numericShopifyId($payload['shopify_order_id'] ?? null, 'Order');
        if ($orderId === null) {
            throw new PersonalizationException('INVALID_PERSONALIZATION_ORDER_EVENT', '结账完成事件缺少订单标识。');
        }

        return [
            'component_id' => null,
            'strategy_id' => null,
            'strategy_version_id' => null,
            'placement' => null,
            'shopify_order_id' => $orderId,
            'products' => [],
        ];
    }

    /** @return list<array{product_id: int, shopify_product_id: string, shopify_variant_id: ?string, rank: ?int}> */
    private function products(Store $store, string $eventName, mixed $value): array
    {
        if (! is_array($value)
            || ! array_is_list($value)
            || ($value === [] && $eventName !== self::CHECKOUT_RECOMMENDATION_SEQUENCE_COMPLETED)
            || count($value) > 50) {
            throw new PersonalizationException('INVALID_PERSONALIZATION_EVENT_PRODUCTS', '推荐事件商品列表无效。');
        }
        if (in_array($eventName, [
            self::CLICK,
            self::ADD_TO_CART,
            self::ADD_SUCCESS,
            self::ADD_FAILED,
            self::CHECKOUT_RECOMMENDATION_IMPRESSION,
            self::CHECKOUT_RECOMMENDATION_CLICK,
            self::CHECKOUT_RECOMMENDATION_ADD_SUCCESS,
            self::CHECKOUT_RECOMMENDATION_ADD_FAILED,
        ], true) && count($value) !== 1) {
            throw new PersonalizationException('INVALID_PERSONALIZATION_EVENT_PRODUCTS', '点击或加购事件必须只包含一个商品。');
        }
        $normalized = [];
        $seen = [];
        foreach ($value as $position => $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new PersonalizationException('INVALID_PERSONALIZATION_EVENT_PRODUCTS', '推荐事件商品格式无效。');
            }
            $productId = $this->numericShopifyId($item['product_id'] ?? null, 'Product');
            $variantId = $this->numericShopifyId($item['variant_id'] ?? null, 'ProductVariant', true);
            $ruleId = trim((string) ($item['rule_id'] ?? ''));
            $rank = filter_var($item['rank'] ?? ($position + 1), FILTER_VALIDATE_INT);
            if ($productId === null || isset($seen[$productId]) || $rank === false || $rank < 1 || $rank > 65535
                || ($ruleId !== '' && ! Str::isUuid($ruleId))) {
                throw new PersonalizationException('INVALID_PERSONALIZATION_EVENT_PRODUCTS', '推荐事件商品字段无效。');
            }
            if (in_array($eventName, [
                self::ADD_TO_CART,
                self::ADD_SUCCESS,
                self::ADD_FAILED,
                self::CHECKOUT_RECOMMENDATION_ADD_SUCCESS,
                self::CHECKOUT_RECOMMENDATION_ADD_FAILED,
            ], true) && $variantId === null) {
                throw new PersonalizationException('INVALID_PERSONALIZATION_EVENT_PRODUCTS', '推荐加购事件缺少变体标识。');
            }
            $seen[$productId] = true;
            $normalized[] = [
                'shopify_product_id' => $productId,
                'shopify_variant_id' => $variantId,
                'rank' => (int) $rank,
                'rule_id' => $ruleId ?: null,
            ];
        }

        /** @var Collection<string, Product> $products */
        $products = Product::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->whereIn('shopify_product_id', array_keys($seen))
            ->get(['id', 'shopify_product_id'])
            ->keyBy(fn (Product $product): string => (string) $product->shopify_product_id);
        if ($products->count() !== count($normalized)) {
            throw new PersonalizationException('INVALID_PERSONALIZATION_EVENT_PRODUCTS', '推荐事件包含非当前店铺商品。');
        }

        foreach ($normalized as &$item) {
            $product = $products->get($item['shopify_product_id']);
            if (! $product) {
                throw new PersonalizationException('INVALID_PERSONALIZATION_EVENT_PRODUCTS', '推荐事件包含非当前店铺商品。');
            }
            if ($item['shopify_variant_id'] !== null
                && ! ProductVariant::query()
                    ->where('product_id', $product->id)
                    ->where('shopify_variant_id', $item['shopify_variant_id'])
                    ->exists()) {
                throw new PersonalizationException('INVALID_PERSONALIZATION_EVENT_PRODUCTS', '推荐事件变体不属于对应商品。');
            }
            $item['product_id'] = $product->id;
        }
        unset($item);

        return $normalized;
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $key, int $maxLength): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $maxLength) {
            throw new PersonalizationException('INVALID_PERSONALIZATION_EVENT', "推荐事件字段 [{$key}] 无效。");
        }

        return trim($value);
    }

    private function timestamp(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            throw new PersonalizationException('INVALID_PERSONALIZATION_EVENT', '推荐事件时间无效。');
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            throw new PersonalizationException('INVALID_PERSONALIZATION_EVENT', '推荐事件时间无效。');
        }
    }

    private function identifierHash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function numericShopifyId(mixed $value, string $resource, bool $nullable = false): ?string
    {
        if ($nullable && ($value === null || $value === '')) {
            return null;
        }
        $normalized = trim((string) $value);
        if (preg_match('/^\d+$/', $normalized) === 1) {
            return $normalized;
        }
        if (preg_match('#^gid://shopify/'.preg_quote($resource, '#').'/(\d+)$#', $normalized, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
