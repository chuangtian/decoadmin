<?php

namespace DecoMarketing\Services;

use App\Models\Store;
use DecoMarketing\Models\Contact;
use DecoMarketing\Models\Delivery;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class Renderer
{
    public const VARIABLES = ['firstName', 'storeName', 'orderName', 'productTitle', 'couponCode', 'referralCode'];

    public function validate(array $content): array
    {
        $values = validator($content, [
            'subject' => 'required|string|max:200', 'body' => 'required|string|max:10000', 'button' => 'required|string|max:80',
        ])->validate();
        foreach ($values as $value) {
            preg_match_all('/\{([^{}]+)\}/u', $value, $matches);
            if (array_diff($matches[1], self::VARIABLES)) {
                throw ValidationException::withMessages(['content' => '模板包含不支持的变量。']);
            }
        }
        if (preg_match('/[\r\n]/', $values['subject'])) {
            throw ValidationException::withMessages(['content' => '主题不能包含换行。']);
        }

        return $values;
    }

    public function destination(Store $store, array $context): string
    {
        $url = $context['url'] ?? 'https://'.$store->shopify_domain;
        if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== $store->shopify_domain
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null || preg_match('/[\x00-\x20\\\\]/', $url)) {
            throw ValidationException::withMessages(['url' => '邮件链接只能指向 macfox-test-app 测试店铺。']);
        }

        return $url;
    }

    public function render(Store $store, Contact $contact, array $content, array $context, ?Delivery $delivery = null): array
    {
        $content = $this->validate($content);
        $variables = ['firstName' => $contact->name_encrypted ?: 'there', 'storeName' => $store->name,
            'orderName' => $context['order_name'] ?? 'your order', 'productTitle' => $context['product_title'] ?? 'your item', 'couponCode' => $context['coupon_code'] ?? '', 'referralCode' => $context['referral_code'] ?? ''];
        foreach (['couponCode', 'referralCode'] as $required) {
            if (str_contains(implode(' ', $content), '{'.$required.'}') && trim((string) $variables[$required]) === '') {
                throw ValidationException::withMessages(['content' => '模板所需优惠码或推荐码尚未配置。']);
            }
        }
        $replace = [];
        foreach ($variables as $key => $value) {
            $replace['{'.$key.'}'] = (string) $value;
        }
        $subject = str_replace(["\r", "\n"], ' ', strtr($content['subject'], $replace));
        $body = strtr($content['body'], $replace);
        $button = strtr($content['button'], $replace);
        $destination = $this->destination($store, $context);
        $click = $delivery ? URL::signedRoute('marketing.click', ['delivery' => $delivery->uuid]) : $destination;
        $unsubscribe = URL::signedRoute('marketing.unsubscribe', array_filter(['contact' => $contact->uuid, 'delivery' => $delivery?->uuid]));
        $products = [];
        foreach (array_slice($context['products'] ?? [], 0, 20) as $product) {
            $image = $product['image'] ?? null;
            if ($image && (parse_url($image, PHP_URL_SCHEME) !== 'https' || parse_url($image, PHP_URL_HOST) !== 'cdn.shopify.com')) $image = null;
            $products[] = ['title' => mb_substr((string) ($product['title'] ?? ''), 0, 200), 'image' => $image,
                'quantity' => max(1, (int) ($product['quantity'] ?? 1)), 'price' => (string) ($product['price'] ?? ''), 'currency' => (string) ($product['currency'] ?? '')];
        }
        $html = view('marketing::email', ['store' => $store, 'body' => $body, 'coupon' => $context['coupon_code'] ?? '', 'products' => $products,
            'click' => $click, 'button' => $button, 'unsubscribe' => $unsubscribe, 'pixel' => $delivery ? URL::signedRoute('marketing.open', ['delivery' => $delivery->uuid]) : null])->render();

        return ['from' => config('marketing.transport') === 'system' ? config('mail.from.address') : config('marketing.from'), 'to' => $contact->email_encrypted, 'subject' => $subject, 'html' => $html, 'text' => $body."\n\n".implode("\n", array_map(fn ($p) => $p['title'].' — '.$p['quantity'].' × '.$p['price'].' '.$p['currency'], $products))."\n\n".$button.': '.$click."\nUnsubscribe: ".$unsubscribe, 'destination' => $destination];
    }
}
