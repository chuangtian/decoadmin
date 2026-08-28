<?php

namespace App\Services\Shopify;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ShopifyDataPrivacyService
{
    /** @param LengthAwarePaginator<Order> $orders */
    public function maskOrders(LengthAwarePaginator $orders): LengthAwarePaginator
    {
        return $orders->through(fn (Order $order): Order => $this->maskOrder($order));
    }

    public function maskOrder(Order $order): Order
    {
        if (! $this->shouldMask()) {
            return $order;
        }

        $order->setAttribute('email', $this->maskEmail($order->email));
        $order->setAttribute('pos_staff_name', $this->maskName($order->pos_staff_name));
        $order->makeHidden([
            'organization_id',
            'store_id',
            'shopify_customer_id',
            'pos_staff_id',
        ]);

        return $order;
    }

    /** @param LengthAwarePaginator<Customer> $customers */
    public function maskCustomers(LengthAwarePaginator $customers): LengthAwarePaginator
    {
        return $customers->through(fn (Customer $customer): Customer => $this->maskCustomer($customer));
    }

    public function maskCustomer(Customer $customer): Customer
    {
        if (! $this->shouldMask()) {
            return $customer;
        }

        $customer->setAttribute('first_name', $this->maskName($customer->first_name));
        $customer->setAttribute('last_name', $this->maskName($customer->last_name));
        $customer->setAttribute('email', $this->maskEmail($customer->email));
        $customer->setAttribute('phone', $this->maskPhone($customer->phone));
        $customer->makeHidden(['organization_id', 'store_id', 'shopify_customer_id']);

        return $customer;
    }

    private function maskName(?string $value): ?string
    {
        $value = $this->normalize($value);

        return $value === null ? null : mb_substr($value, 0, 1).'***';
    }

    private function maskEmail(?string $value): ?string
    {
        $value = $this->normalize($value);
        if ($value === null) {
            return null;
        }

        if (! str_contains($value, '@')) {
            return mb_substr($value, 0, 1).'***';
        }

        [$local, $domain] = explode('@', $value, 2);
        $maskedLocal = $local === '' ? '***' : mb_substr($local, 0, 1).'***';

        return $maskedLocal.'@'.$domain;
    }

    private function maskPhone(?string $value): ?string
    {
        $value = $this->normalize($value);
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/u', '', $value) ?? '';

        return mb_strlen($digits) > 4 ? '***'.mb_substr($digits, -4) : '***';
    }

    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function shouldMask(): bool
    {
        return app()->environment('testing', 'staging');
    }
}
