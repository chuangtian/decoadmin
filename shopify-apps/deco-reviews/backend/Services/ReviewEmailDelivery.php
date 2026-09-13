<?php

namespace DecoReviews\Services;

use App\Models\Store;
use App\Services\SystemSettingsService;
use DecoReviews\Models\EmailDelivery;
use Illuminate\Support\Facades\Mail;

class ReviewEmailDelivery
{
    public function allowed(Store $store, EmailDelivery $delivery): bool
    {
        if (! config('deco_reviews.delivery_enabled') || ! in_array($store->shopify_domain, config('deco_reviews.automation_stores', []), true)) {
            return false;
        }
        app(ReviewService::class)->active($store);
        $settings = app(ReviewService::class)->settings($store);
        if (! in_array($delivery->type, ReviewEmail::TYPES, true) || ! ($settings[$delivery->type.'_enabled'] ?? false)) {
            return false;
        }
        if (config('deco_reviews.environment') !== 'production'
            && ! in_array(strtolower($delivery->recipient), array_map('strtolower', config('deco_reviews.recipient_allowlist', [])), true)) {
            return false;
        }
        app(SystemSettingsService::class)->applyRuntimeConfiguration();

        return config('mail.mailers.'.config('mail.default').'.transport') === 'smtp'
            && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false;
    }

    public function send(Store $store, EmailDelivery $delivery): bool
    {
        if (! $this->allowed($store, $delivery) || $delivery->fresh()?->status !== 'sending') {
            return false;
        }
        $review = $delivery->review()->with('product')->firstOrFail();
        $content = app(ReviewEmail::class)->content($store, $review, $delivery->type);
        $settings = app(ReviewService::class)->settings($store);
        $sent = Mail::send(['html' => 'deco-reviews::emails.review-message', 'text' => 'deco-reviews::emails.review-message-text'], $content,
            function ($message) use ($store, $delivery, $settings, $content) {
                $senderName = trim(str_replace(["\r", "\n"], ' ', $store->name)).' Reviews';
                $message->from(config('mail.from.address'), $senderName);
                $message->to($delivery->recipient)->subject($content['subject']);
                if ($settings['reply_to']) {
                    $message->replyTo($settings['reply_to']);
                }
                $message->getSymfonyMessage()->getHeaders()->addIdHeader('Message-ID', $delivery->uuid.'@'.parse_url(config('app.url'), PHP_URL_HOST));
            });

        return $sent !== null;
    }
}
