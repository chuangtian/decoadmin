<?php

namespace DecoReviews\Services;

use App\Models\Store;
use DecoReviews\Models\Review;

class ReviewEmail
{
    public const TYPES = ['product_thank_you', 'store_thank_you', 'reply_notification'];

    public function content(Store $store, ?Review $review, string $type): array
    {
        app(ReviewService::class)->active($store);
        abort_unless(in_array($type, self::TYPES, true), 422);
        if ($review) {
            abort_unless((int) $review->store_id === (int) $store->id && (int) $review->organization_id === (int) $store->organization_id, 404);
        }
        $settings = app(ReviewService::class)->settings($store);
        $variables = [
            '{store}' => $store->name,
            '{product}' => $review?->product?->title ?? 'DEMO product',
            '{author}' => $review?->author_name ?? 'DEMO customer',
            '{rating}' => (string) ($review?->rating ?? 5),
            '{reply}' => $review?->reply ?? 'DEMO store reply',
        ];

        return [
            'storeName' => $store->name,
            'productName' => $variables['{product}'],
            'preview' => $review === null,
            'type' => $type,
            'subject' => str_replace(["\r", "\n"], ' ', strtr($settings[$type.'_subject'], $variables)),
            'body' => strtr($settings[$type.'_body'], $variables),
            'accent' => $settings['email_accent'],
            'reply' => $type === 'reply_notification' ? $variables['{reply}'] : null,
        ];
    }
}
