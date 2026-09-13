<?php

namespace DecoReviews\Services;

use App\Models\Store;
use App\Services\SystemSettingsService;
use DecoReviews\Models\Invitation;
use Illuminate\Support\Facades\Mail;

class InvitationDelivery
{
    public function allowed(Store $store, Invitation $invite): bool
    {
        // Store-level automation must never be enabled merely by adding a theme block.
        if (! config('deco_reviews.delivery_enabled') || ! in_array($store->shopify_domain, config('deco_reviews.automation_stores', []), true)) {
            return false;
        }
        app(ReviewService::class)->active($store);
        if (! app(ReviewService::class)->settings($store)['invites_enabled']) {
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
        $state = $invite->fresh()?->status;
        if (! in_array($state, ['sending', 'sending_reminder'], true) || app(InvitationService::class)->suppressed($store, $invite->email_hash)) {
            return false;
        }
        $settings = app(ReviewService::class)->settings($store);
        if ($state === 'sending_reminder' && ! $settings['reminders_enabled']) {
            return false;
        }
        $kind = $state === 'sending_reminder' ? 'reminder' : 'initial';
        $content = app(InvitationEmail::class)->content($store, $invite, $kind);
        // SMTP result uncertainty is held by the caller, never silently retried.
        $sent = Mail::send('deco-reviews::emails.invitation', $content, function ($message) use ($invite, $settings, $content, $kind) {
            $message->to($invite->email)->subject($content['subject']);
            if ($settings['reply_to']) {
                $message->replyTo($settings['reply_to']);
            }
            $message->getSymfonyMessage()->getHeaders()->addIdHeader('Message-ID', $invite->uuid.'.'.$kind.'@'.parse_url(config('app.url'), PHP_URL_HOST));
        });

        return $sent !== null;
    }
}
