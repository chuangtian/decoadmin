<?php

namespace DecoMarketing\Services;

use App\Models\Store;
use DecoMarketing\Models\Flow;
use DecoMarketing\Models\Settings;
use DecoMarketing\Models\Template;

class Catalog
{
    public const FLOWS = [
        'welcome' => ['欢迎系列', [['welcome_1', 0], ['welcome_2', 2880], ['welcome_3', 7200], ['welcome_4', 17280]]],
        'abandoned' => ['弃购挽回', [['abandoned_1', 60], ['abandoned_3', 4320]]],
        'payment' => ['待付款提醒', [['payment_1', 240], ['payment_2', 1440]]],
        'back_in_stock' => ['到货通知', [['back_in_stock', 0]]],
        'cancelled' => ['取消订单关怀', [['cancelled', 4320]]],
        'advocacy' => ['发货后回访', [['advocacy', 14400]]],
    ];

    public const TEMPLATES = [
        'welcome_1' => ['欢迎 · 第一封', 'Welcome to {storeName}', 'Hi {firstName}, welcome! Explore our collection and find your next ride.'],
        'welcome_2' => ['欢迎 · 第二封', 'Meet your next ride', 'Discover what makes our bikes special.'],
        'welcome_3' => ['欢迎 · 第三封', 'Questions before your first ride?', 'Our team is here to help you choose.'],
        'welcome_4' => ['欢迎 · 第四封', 'Which bike is right for you?', 'Explore our bikes at your own pace.'],
        'abandoned_1' => ['弃购 · 第一封', 'Your checkout is waiting', 'Hi {firstName}, you can return to your checkout below.'],
        'abandoned_2' => ['弃购 #2（24小时 FAQ+分期）', 'Questions about your MACFOX? Answered.', ''],
        'abandoned_3' => ['弃购 · 后续提醒', 'Still thinking it over?', 'Your selected items are waiting. Contact our team if you need help.'],
        'payment_1' => ['待付款 · 第一封', 'Payment pending for {orderName}', 'You can review your order and finish payment using the secure link below.'],
        'payment_2' => ['待付款 · 第二封', 'Reminder about {orderName}', 'Your order is still awaiting payment. If you need help, contact our team.'],
        'back_in_stock' => ['到货通知', '{productTitle} is back in stock', 'The item you requested is available again. Availability can change.'],
        'cancelled' => ['取消订单关怀', 'Can we help with {orderName}?', 'If you need assistance with your cancelled order, our team is here to help.'],
        'advocacy' => ['发货后回访', 'How is your {productTitle}?', 'We hope you are enjoying your ride. Contact our team if you need help.'],
    ];

    public function defaultContent(string $key): ?array
    {
        $default = self::TEMPLATES[$key] ?? null;

        return $default && $default[2] !== '' ? ['subject' => $default[1], 'body' => $default[2], 'button' => 'Visit our store'] : null;
    }

    public function setup(Store $store): void
    {
        app(Guard::class)->store($store);
        Settings::firstOrCreate(['store_id' => $store->id, 'organization_id' => $store->organization_id], ['cutover_at' => now(), 'popup' => ['enabled' => false, 'heading' => 'Join our community', 'body' => 'Get product news and updates.']]);
        foreach (self::TEMPLATES as $key => [$name,$subject,$body]) {
            $content = ['subject' => $subject, 'body' => $body, 'button' => 'Visit our store'];
            Template::firstOrCreate(['store_id' => $store->id, 'organization_id' => $store->organization_id, 'key' => $key], ['name' => $name, 'draft' => $content, 'published' => $body !== '' ? $content : null, 'version' => $body !== '' ? 1 : 0]);
        }
        foreach (self::FLOWS as $key => [$name,$steps]) {
            Flow::firstOrCreate(['store_id' => $store->id, 'organization_id' => $store->organization_id, 'key' => $key], ['name' => $name, 'steps' => array_map(fn ($s) => ['template' => $s[0], 'after_minutes' => $s[1]], $steps)]);
        }
    }

    public function settings(Store $store): Settings
    {
        app(Guard::class)->store($store);

        return Settings::forStore($store)->firstOrFail();
    }
}
