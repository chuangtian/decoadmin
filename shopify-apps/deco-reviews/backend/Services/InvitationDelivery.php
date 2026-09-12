<?php

namespace DecoReviews\Services;

use App\Models\Store;
use App\Services\SystemSettingsService;
use DecoReviews\Models\Invitation;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class InvitationDelivery
{
    public function allowed(Store $store, Invitation $invite): bool
    {
        // Store-level automation must never be enabled merely by adding a theme block.
        if (! config('deco_reviews.delivery_enabled') || ! in_array($store->shopify_domain, config('deco_reviews.automation_stores', []), true)) {
            return false;
        }
        if (config('deco_reviews.environment') !== 'production'
            && ! in_array(strtolower($invite->email), array_map('strtolower', config('deco_reviews.recipient_allowlist', [])), true)) {
            return false;
        }
        app(SystemSettingsService::class)->applyRuntimeConfiguration();

        return config('mail.mailers.'.config('mail.default').'.transport') === 'smtp' && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false;
    }

    public function send(Store $store, Invitation $invite): bool
    {
        if (! $this->allowed($store, $invite)) {
            return false;
        }
        if ($invite->fresh()?->status !== 'sending' || app(InvitationService::class)->suppressed($store, $invite->email_hash)) {
            return false;
        }
        $settings = app(ReviewService::class)->settings($store);
        $link = app(InvitationService::class)->link($invite);
        $unsubscribe = URL::temporarySignedRoute('deco-reviews.unsubscribe', $invite->expires_at, ['invitation' => $invite->uuid]);
        $text = strtr($settings['email_body'], ['{product}' => $invite->product?->title ?? 'your purchase', '{store}' => $store->name]);
        $text .= "\n\nWrite a review: ".$link."\n\nStop review invitations: ".$unsubscribe;
        // SMTP result uncertainty is held by the caller, never silently retried.
        $sent = Mail::raw($text, function ($message) use ($invite, $settings) {
            $message->to($invite->email)->subject(str_replace(["\r", "\n"], ' ', $settings['subject']));
            if ($settings['reply_to']) {
                $message->replyTo($settings['reply_to']);
            }
            $message->getSymfonyMessage()->getHeaders()->addIdHeader('Message-ID', $invite->uuid.'@'.parse_url(config('app.url'), PHP_URL_HOST));
        });

        return $sent !== null;
    }
}
