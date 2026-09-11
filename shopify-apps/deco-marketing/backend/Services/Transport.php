<?php

namespace DecoMarketing\Services;

use App\Models\Store;
use DecoMarketing\Models\Delivery;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Http;

class Transport
{
    /** Return accepted, retry, failed or uncertain. Never log provider bodies or recipient payloads. */
    public function send(Store $store, Delivery $delivery): array
    {
        app(Guard::class)->store($store);
        $payload = $delivery->payload_encrypted;
        abort_unless($delivery->store_id === $store->id && $delivery->organization_id === $store->organization_id, 403);
        if (app(Guard::class)->previewOnly($store) || config('marketing.transport') === 'preview') {
            return ['status' => 'simulated', 'id' => null, 'reason' => 'preview'];
        }
        if (! app(Guard::class)->recipient($payload['to'])) {
            return ['status' => 'failed', 'id' => null, 'reason' => 'recipient_not_allowed'];
        }
        // Older attempted messages without a sender snapshot cannot be retried safely.
        if (! array_key_exists('from', $payload)) {
            return ['status' => 'held', 'id' => null, 'reason' => 'sender_snapshot_missing'];
        }
        if (config('marketing.transport') === 'system') {
            // SMTP has no provider idempotency. Unknown first outcomes are held, never retried.
            if ($delivery->attempts > 1) {
                return ['status' => 'held', 'id' => null, 'reason' => 'smtp_result_requires_review'];
            }
            $mailer = config('mail.default');
            if (config("mail.mailers.{$mailer}.transport") !== 'smtp' || ! filter_var($payload['from'], FILTER_VALIDATE_EMAIL)) {
                return ['status' => 'failed', 'id' => null, 'reason' => 'transport_not_configured'];
            }
            try {
                $sent = app(MailManager::class)->mailer($mailer)->send([], [], function ($message) use ($payload, $delivery) {
                    $message->to($payload['to'])->from($payload['from'])->subject($payload['subject']);
                    $email = $message->getSymfonyMessage();
                    $email->html($payload['html'])->text($payload['text']);
                    $email->getHeaders()->addIdHeader('Message-ID', $delivery->uuid.'@'.parse_url(config('app.url'), PHP_URL_HOST));
                });

                return ['status' => $sent ? 'sent' : 'held', 'id' => $sent?->getMessageId(), 'reason' => $sent ? null : 'smtp_result_requires_review'];
            } catch (\Throwable) {
                return ['status' => 'held', 'id' => null, 'reason' => 'smtp_result_requires_review'];
            }
        }
        if (config('marketing.transport') !== 'resend' || ! config('marketing.resend_key') || ! filter_var($payload['from'], FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'failed', 'id' => null, 'reason' => 'transport_not_configured'];
        }
        try {
            $response = Http::withToken(config('marketing.resend_key'))->acceptJson()->withHeaders(['Idempotency-Key' => $delivery->dedupe_key])
                ->connectTimeout(5)->timeout(20)->post('https://api.resend.com/emails', [
                    'from' => $payload['from'], 'to' => [$payload['to']], 'subject' => $payload['subject'],
                    'html' => $payload['html'], 'text' => $payload['text'],
                    'tags' => [['name' => 'delivery', 'value' => $delivery->uuid]],
                ]);
            if ($response->successful() && is_string($response->json('id'))) {
                return ['status' => 'sent', 'id' => $response->json('id'), 'reason' => null];
            }
            if ($response->status() === 429) {
                return ['status' => 'retry', 'id' => null, 'reason' => 'rate_limited'];
            }
            if ($response->serverError() || $response->status() === 409 || $response->successful()) {
                return ['status' => 'uncertain', 'id' => null, 'reason' => 'provider_result_unknown'];
            }

            return ['status' => 'failed', 'id' => null, 'reason' => 'provider_rejected'];
        } catch (\Throwable) {
            return ['status' => 'uncertain', 'id' => null, 'reason' => 'provider_result_unknown'];
        }
    }
}
