<?php

namespace DecoMarketing\Services;

use DecoMarketing\Models\Delivery;
use Illuminate\Support\Facades\DB;

class OpenTracking
{
    public const VERSION = 'ua-conservative-v1';

    /** Filtered estimate, not proof of a person. No IP or full user agent is retained. */
    public function record(Delivery $delivery, string $userAgent, string $purpose = ''): void
    {
        app(Guard::class)->store($delivery->store);
        DB::transaction(function () use ($delivery, $userAgent, $purpose) {
            $delivery = Delivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            if (! $delivery->sent_at) {
                return;
            }
            $ua = strtolower(substr($userAgent, 0, 1000));
            $machine = preg_match('/bot|crawler|spider|prefetch|preview|headless|googleimageproxy|yahoo|thunderbird\/.*proxy/', $ua)
                || str_contains(strtolower($purpose), 'prefetch');
            // Apple privacy proxies may impersonate normal clients: ambiguous clients stay unknown.
            $candidate = ! $machine && preg_match('/chrome\/|firefox\/|edg\//', $ua) && $delivery->sent_at->lte(now()->subMinute());
            $values = ['opened_at' => $delivery->opened_at ?? now(), 'open_classifier' => self::VERSION];
            if ($machine) {
                $values['machine_opened_at'] = $delivery->machine_opened_at ?? now();
            }
            if ($candidate) {
                $values['human_opened_at'] = $delivery->human_opened_at ?? now();
            }
            $delivery->update($values);
        });
    }
}
