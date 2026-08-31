<?php

namespace App\Services\Personalization;

use App\Exceptions\PersonalizationException;
use App\Exceptions\ShopifyApiException;
use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use App\Services\Shopify\ShopifyGraphQLClient;

class PersonalizationDiscountService
{
    private const LIST_QUERY = <<<'GRAPHQL'
        query ListPersonalizationDiscounts($first: Int!) {
          discountNodes(first: $first, sortKey: UPDATED_AT, reverse: true) {
            nodes {
              id
              discount {
                __typename
                ... on DiscountCodeBasic {
                  title
                  summary
                  status
                  codes(first: 1) { nodes { code } }
                  customerGets {
                    value {
                      ... on DiscountPercentage { percentage }
                    }
                  }
                }
              }
            }
          }
        }
        GRAPHQL;

    private const CREATE_MUTATION = <<<'GRAPHQL'
        mutation CreatePersonalizationDiscount($basicCodeDiscount: DiscountCodeBasicInput!) {
          discountCodeBasicCreate(basicCodeDiscount: $basicCodeDiscount) {
            codeDiscountNode { id }
            userErrors { field message code }
          }
        }
        GRAPHQL;

    private const UPDATE_MUTATION = <<<'GRAPHQL'
        mutation UpdatePersonalizationDiscount($id: ID!, $basicCodeDiscount: DiscountCodeBasicInput!) {
          discountCodeBasicUpdate(id: $id, basicCodeDiscount: $basicCodeDiscount) {
            codeDiscountNode { id }
            userErrors { field message code }
          }
        }
        GRAPHQL;

    public function __construct(
        private ShopifyGraphQLClient $shopify,
        private PersonalizationAppTokenService $tokens,
        private PersonalizationShopGuard $shopGuard,
    ) {}

    /** @return list<array{id: string, title: string, summary: string, status: string, code: string, editable: bool}> */
    public function listing(Store $store, User $actor): array
    {
        $this->authorize($store, $actor);
        $payload = $this->query($store, self::LIST_QUERY, ['first' => 50]);

        return collect(data_get($payload, 'data.discountNodes.nodes', []))
            ->map(function (mixed $node): ?array {
                $discount = is_array($node) ? ($node['discount'] ?? null) : null;
                if (! is_array($discount)) {
                    return null;
                }

                return [
                    'id' => (string) ($node['id'] ?? ''),
                    'title' => (string) ($discount['title'] ?? '未命名折扣'),
                    'summary' => (string) ($discount['summary'] ?? ''),
                    'status' => strtolower((string) ($discount['status'] ?? 'unknown')),
                    'code' => (string) data_get($discount, 'codes.nodes.0.code', ''),
                    'percentage' => $this->percentage(data_get($discount, 'customerGets.value.percentage')),
                    'editable' => ($discount['__typename'] ?? '') === 'DiscountCodeBasic',
                ];
            })
            ->filter(fn (?array $row): bool => $row !== null && $row['id'] !== '')
            ->values()->all();
    }

    /** @param array{title: string, code: string, percentage: int, product_ids: list<string>} $input */
    public function create(Store $store, User $actor, array $input): array
    {
        $this->authorize($store, $actor);
        $payload = $this->query($store, self::CREATE_MUTATION, [
            'basicCodeDiscount' => $this->input($input),
        ]);
        $id = $this->resultId($payload, 'discountCodeBasicCreate');
        $this->audit($store, $actor, 'personalization_discount_created', $id, $input);

        return ['id' => $id, 'title' => $input['title'], 'summary' => $input['percentage'].'% off', 'status' => 'active', 'code' => $input['code'], 'percentage' => $input['percentage'], 'editable' => true];
    }

    /** @param array{id: string, title: string, code: string, percentage: int, product_ids: list<string>} $input */
    public function update(Store $store, User $actor, array $input): array
    {
        $this->authorize($store, $actor);
        $payload = $this->query($store, self::UPDATE_MUTATION, [
            'id' => $input['id'],
            'basicCodeDiscount' => $this->input($input),
        ]);
        $id = $this->resultId($payload, 'discountCodeBasicUpdate');
        $this->audit($store, $actor, 'personalization_discount_updated', $id, $input);

        return ['id' => $id, 'title' => $input['title'], 'summary' => $input['percentage'].'% off', 'status' => 'active', 'code' => $input['code'], 'percentage' => $input['percentage'], 'editable' => true];
    }

    /** @param array{title: string, code: string, percentage: int, product_ids: list<string>} $input */
    private function input(array $input): array
    {
        return [
            'title' => $input['title'],
            'code' => strtoupper($input['code']),
            'startsAt' => now()->utc()->toIso8601String(),
            'context' => ['all' => 'ALL'],
            'customerGets' => [
                'value' => ['percentage' => (float) $input['percentage'] / 100],
                'items' => ['products' => ['productsToAdd' => array_map(
                    fn (string $id): string => "gid://shopify/Product/{$id}",
                    $input['product_ids'],
                )]],
            ],
            'minimumRequirement' => ['quantity' => ['greaterThanOrEqualToQuantity' => '1']],
            'appliesOncePerCustomer' => false,
        ];
    }

    private function resultId(array $payload, string $operation): string
    {
        $errors = data_get($payload, "data.{$operation}.userErrors", []);
        $id = data_get($payload, "data.{$operation}.codeDiscountNode.id");
        if (! is_string($id) || $id === '') {
            $message = collect(is_array($errors) ? $errors : [])->pluck('message')->filter()->take(3)->implode('；');
            throw new PersonalizationException('SHOPIFY_DISCOUNT_WRITE_FAILED', $message ?: 'Shopify 未能保存折扣。', 502);
        }

        return $id;
    }

    private function percentage(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $percentage = (float) $value;

        return $percentage > 0 && $percentage < 1
            ? round($percentage * 100, 4)
            : ($percentage >= 1 && $percentage <= 100 ? round($percentage, 4) : null);
    }

    /** @param array<string, mixed> $variables */
    private function query(Store $store, string $query, array $variables): array
    {
        try {
            return $this->shopify->queryWithAccessToken(
                $this->shopGuard->assertAllowed((string) $store->shopify_domain),
                $this->tokens->accessTokenFor($store),
                $query,
                $variables,
                apiVersion: '2026-07',
            );
        } catch (ShopifyApiException) {
            throw new PersonalizationException('SHOPIFY_DISCOUNT_API_FAILED', 'Shopify 折扣服务暂时不可用，请稍后重试。', 502);
        }
    }

    private function authorize(Store $store, User $actor): void
    {
        $this->shopGuard->assertAllowed((string) $store->shopify_domain);
        if (! $actor->canAccessStore($store)
            || ! $actor->hasPermission('personalization.manage', $store->organization, $store)) {
            throw new PersonalizationException('PERSONALIZATION_ACCESS_DENIED', '无权管理当前店铺的推荐折扣。', 403);
        }
    }

    /** @param array<string, mixed> $input */
    private function audit(Store $store, User $actor, string $action, string $shopifyId, array $input): void
    {
        AuditLog::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'user_id' => $actor->id,
            'action' => $action,
            'subject_type' => Store::class,
            'subject_id' => $store->id,
            'metadata' => [
                'shopify_discount_id' => $shopifyId,
                'title' => $input['title'],
                'percentage' => $input['percentage'],
                'product_count' => count($input['product_ids']),
            ],
        ]);
    }
}
