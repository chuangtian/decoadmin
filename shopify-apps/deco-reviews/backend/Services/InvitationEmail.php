<?php

namespace DecoReviews\Services;

use App\Models\Store;
use DecoReviews\Models\Invitation;
use Illuminate\Support\Facades\URL;

class InvitationEmail
{
    public const TYPES = ['initial', 'reminder', 'media_reminder'];

    public function content(Store $store, ?Invitation $invite = null, string $kind = 'initial'): array
    {
        app(ReviewService::class)->active($store);
        abort_unless(in_array($kind, self::TYPES, true), 422);
        if ($invite) {
            abort_unless((int) $invite->store_id === (int) $store->id && (int) $invite->organization_id === (int) $store->organization_id, 404);
        }
        $settings = app(ReviewService::class)->settings($store);
        $variables = ['{store}' => $store->name, '{product}' => $invite?->product?->title ?? 'DEMO product'];

        $prefix = match ($kind) {
            'reminder' => 'reminder_', 'media_reminder' => 'media_reminder_', default => '',
        };

        return ['storeName' => $store->name, 'productName' => $variables['{product}'], 'preview' => $invite === null,
            'subject' => str_replace(["\r", "\n"], ' ', strtr($settings[$prefix.'subject'], $variables)),
            'body' => strtr($settings[$prefix === '' ? 'email_body' : $prefix.'body'], $variables),
            'buttonLabel' => $settings['email_button_label'], 'accent' => $settings['email_accent'],
            'reviewUrl' => $invite ? app(InvitationService::class)->link($invite) : '#preview',
            'unsubscribeUrl' => $invite ? URL::temporarySignedRoute('deco-reviews.unsubscribe', $invite->expires_at, ['invitation' => $invite->uuid]) : '#preview',
        ];
    }
}
