<?php

namespace DecoMarketing\Controllers;

use DecoMarketing\Models\Contact;
use DecoMarketing\Models\Delivery;
use DecoMarketing\Models\PopupEvent;
use DecoMarketing\Models\Settings;
use DecoMarketing\Services\Contacts;
use DecoMarketing\Services\Engine;
use DecoMarketing\Services\Guard;
use DecoMarketing\Services\OpenTracking;
use DecoMarketing\Services\Renderer;
use Illuminate\Http\Request;

class PublicController
{
    public function unsubscribe(Request $request, string $contact)
    {
        $model = Contact::where('uuid', $contact)->firstOrFail();
        app(Guard::class)->store($model->store);
        if ($request->isMethod('post')) {
            app(Contacts::class)->unsubscribe($model);
            if ($request->query('delivery')) {
                Delivery::forStore($model->store)->where('contact_id', $model->id)->where('uuid', $request->query('delivery'))->whereNotNull('sent_at')->whereNull('unsubscribed_at')->update(['unsubscribed_at' => now()]);
            }
        }

        return response()->view('marketing::unsubscribe', ['done' => $request->isMethod('post') || $model->consent === 'unsubscribed', 'action' => $request->fullUrl()])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function opened(Request $request, string $delivery)
    {
        $model = Delivery::where('uuid', $delivery)->firstOrFail();
        app(OpenTracking::class)->record($model, (string) $request->userAgent(), (string) $request->header('Sec-Purpose', $request->header('Purpose', '')));

        return response(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 200, ['Content-Type' => 'image/gif', 'Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer']);
    }

    public function click(Request $request, string $delivery)
    {
        $model = Delivery::where('uuid', $delivery)->firstOrFail();
        app(Guard::class)->store($model->store);
        $url = app(Renderer::class)->destination($model->store, ['url' => $model->payload_encrypted['destination'] ?? '']);
        if ($model->sent_at && ! $model->clicked_at) {
            $model->update(['clicked_at' => now()]);
        }

        return redirect()->away($url)->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
    }

    public function popup(Request $request)
    {
        $store = $request->attributes->get('marketing_store');
        app(Guard::class)->store($store);
        abort_if(app(Guard::class)->previewOnly($store), 404);
        $settings = Settings::forStore($store)->first();
        if (! $settings?->enabled || ! data_get($settings->popup, 'enabled')) {
            return response()->json(['enabled' => false]);
        }

        return response()->json(['enabled' => true, 'heading' => data_get($settings->popup, 'heading'), 'body' => data_get($settings->popup, 'body')]);
    }

    public function subscribe(Request $request)
    {
        $store = $request->attributes->get('marketing_store');
        app(Guard::class)->store($store);
        abort_if(app(Guard::class)->previewOnly($store), 404);
        $settings = Settings::forStore($store)->first();
        abort_unless($settings?->enabled && data_get($settings->popup, 'enabled'), 404);
        $v = $request->validate(['email' => 'required|email|max:254', 'name' => 'nullable|string|max:160', 'consent' => 'required|accepted', 'visitor' => 'required|uuid']);
        $contact = app(Contacts::class)->upsert($store, ['email' => $v['email'], 'name' => $v['name'] ?? '', 'consent' => 'subscribed', 'consent_at' => now()->toIso8601String()], 'storefront');
        app(Engine::class)->enroll($store, $contact, 'welcome', 'signup:'.$contact->uuid);

        return response()->json(['message' => 'Thank you. Your subscription request has been received.']);
    }

    public function event(Request $request)
    {
        $store = $request->attributes->get('marketing_store');
        app(Guard::class)->store($store);
        abort_if(app(Guard::class)->previewOnly($store), 404);
        $v = $request->validate(['visitor' => 'required|uuid', 'type' => 'required|in:impression,submit,close']);
        $settings = Settings::forStore($store)->first();
        abort_unless($settings?->enabled && data_get($settings->popup, 'enabled'), 404);
        $this->record($store, $v['visitor'], $v['type']);

        return response()->json(['ok' => true]);
    }

    private function record($store, string $visitor, string $type): void
    {
        $hash = hash_hmac('sha256', $visitor, (string) config('app.key'));
        PopupEvent::firstOrCreate(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'visitor_hash' => $hash, 'type' => $type, 'day' => now('UTC')->toDateString()]);
    }
}
