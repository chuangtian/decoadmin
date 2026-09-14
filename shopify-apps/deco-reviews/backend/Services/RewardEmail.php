<?php

namespace DecoReviews\Services;

use App\Models\Store;
use DecoReviews\Models\Review;
use DecoReviews\Models\Reward;

class RewardEmail
{
    public const TYPES = ['reward_issued', 'reward_reminder'];

    public function content(Store $store, ?Reward $reward, string $type): array
    {
        app(ReviewService::class)->active($store);
        abort_unless(in_array($type, self::TYPES, true), 422);
        if ($reward) {
            abort_unless((int) $reward->organization_id === (int) $store->organization_id && (int) $reward->store_id === (int) $store->id, 404);
            $review = Review::where('organization_id', $store->organization_id)->where('store_id', $store->id)
                ->whereKey($reward->review_id)->with(['product' => fn ($product) => $product
                ->where('organization_id', $store->organization_id)->where('store_id', $store->id)])->first();
            abort_unless($review, 404);
            $reward->setRelation('review', $review);
        }
        $settings = app(ReviewService::class)->settings($store);
        $variables = [
            '{store}' => $store->name,
            '{product}' => $reward?->review?->product?->title ?? 'DEMO product',
            '{author}' => $reward?->review?->author_name ?? 'DEMO customer',
            '{value}' => $this->valueLabel($reward),
            '{expires}' => $reward?->expires_at?->toDateString() ?? now()->addDays(30)->toDateString(),
            '{code}' => $reward?->code ?? 'DECO-DEMO-CODE',
        ];

        return [
            'storeName' => $store->name,
            'productName' => $variables['{product}'],
            'preview' => $reward === null,
            'type' => $type,
            'subject' => str_replace(["\r", "\n"], ' ', strtr($settings[$type.'_subject'], $variables)),
            'body' => strtr($settings[$type.'_body'], $variables),
            'accent' => $settings['email_accent'],
            'code' => $variables['{code}'],
            'expires' => $variables['{expires}'],
        ];
    }

    private function valueLabel(?Reward $reward): string
    {
        if (! $reward || $reward->discount_kind === 'percentage') {
            return ($reward?->value ?? 10).'% off';
        }
        if ($reward->discount_kind === 'free_shipping') {
            return 'free shipping';
        }

        return trim(($reward->currency ?? '').' '.$reward->value);
    }
}
