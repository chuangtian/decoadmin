<?php

namespace App\Services\Shopify\Customers;

use App\Exceptions\ShopifyApiException;
use App\Models\Customer;
use App\Models\Store;

class ShopifyCustomerDataService
{
    /**
     * Persist one complete Shopify customer snapshot.
     *
     * This entry point is intentionally reusable by future customers/create,
     * customers/update and customers/delete webhook handlers.
     *
     * @param  array<string, mixed>  $customerNode
     * @return array{customer: Customer, created: bool, currency: string}
     */
    public function upsert(Store $store, array $customerNode): array
    {
        $shopifyCustomerId = $this->numericId($customerNode['id'] ?? null);
        $customer = Customer::query()->firstOrNew([
            'store_id' => $store->getKey(),
            'shopify_customer_id' => $shopifyCustomerId,
        ]);
        $created = ! $customer->exists;
        $amountSpent = $customerNode['amountSpent'] ?? null;

        if (! is_array($amountSpent)) {
            throw new ShopifyApiException('Shopify Customer 数据缺少 Amount Spent。');
        }

        $amount = $amountSpent['amount'] ?? null;
        $currency = $amountSpent['currencyCode'] ?? null;

        if (! is_scalar($amount) || ! is_numeric((string) $amount)) {
            throw new ShopifyApiException('Shopify Customer 缺少有效累计消费金额。');
        }

        if (! is_string($currency) || $currency === '') {
            throw new ShopifyApiException('Shopify Customer 缺少累计消费币种。');
        }

        $verifiedEmail = $customerNode['verifiedEmail'] ?? null;

        if (! is_bool($verifiedEmail)) {
            throw new ShopifyApiException('Shopify Customer Verified Email 格式无效。');
        }

        $customer->fill([
            'organization_id' => $store->organization_id,
            'first_name' => $this->nullableString($customerNode['firstName'] ?? null),
            'last_name' => $this->nullableString($customerNode['lastName'] ?? null),
            'email' => $this->nestedString($customerNode['defaultEmailAddress'] ?? null, 'emailAddress'),
            'phone' => $this->nestedString($customerNode['defaultPhoneNumber'] ?? null, 'phoneNumber'),
            'state' => $this->normalizedState($customerNode['state'] ?? null),
            'verified_email' => $verifiedEmail,
            'orders_count' => $this->unsignedInteger($customerNode['numberOfOrders'] ?? null),
            'total_spent' => (string) $amount,
            'created_at_shopify' => $this->requiredDate($customerNode['createdAt'] ?? null, 'createdAt'),
            'updated_at_shopify' => $this->requiredDate($customerNode['updatedAt'] ?? null, 'updatedAt'),
            'synced_at' => now(),
        ])->save();

        return [
            'customer' => $customer,
            'created' => $created,
            'currency' => strtoupper($currency),
        ];
    }

    private function numericId(mixed $gid): string
    {
        if (! is_string($gid) || preg_match('#^gid://shopify/Customer/([0-9]+)$#', $gid, $matches) !== 1) {
            throw new ShopifyApiException('Shopify Customer ID 格式无效。');
        }

        return $matches[1];
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function nestedString(mixed $value, string $key): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new ShopifyApiException("Shopify Customer 字段 [{$key}] 格式无效。");
        }

        return $this->nullableString($value[$key] ?? null);
    }

    private function normalizedState(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? strtolower($value) : null;
    }

    private function unsignedInteger(mixed $value): string
    {
        if (! is_int($value) && ! is_string($value)) {
            throw new ShopifyApiException('Shopify Customer Number Of Orders 格式无效。');
        }

        $normalized = (string) $value;

        if (preg_match('/^[0-9]+$/', $normalized) !== 1) {
            throw new ShopifyApiException('Shopify Customer Number Of Orders 格式无效。');
        }

        return $normalized;
    }

    private function requiredDate(mixed $value, string $field): string
    {
        if (! is_string($value) || strtotime($value) === false) {
            throw new ShopifyApiException("Shopify Customer 日期 [{$field}] 格式无效。");
        }

        return $value;
    }
}
