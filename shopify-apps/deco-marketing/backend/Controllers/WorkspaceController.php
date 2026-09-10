<?php

namespace DecoMarketing\Controllers;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Store;
use App\Support\CurrentStore;
use DecoMarketing\Models\Attribution;
use DecoMarketing\Models\Campaign;
use DecoMarketing\Models\Contact;
use DecoMarketing\Models\Delivery;
use DecoMarketing\Models\Enrollment;
use DecoMarketing\Models\Flow;
use DecoMarketing\Models\Settings;
use DecoMarketing\Models\Template;
use DecoMarketing\Models\Waitlist;
use DecoMarketing\Services\Catalog;
use DecoMarketing\Services\Contacts;
use DecoMarketing\Services\ConversionReports;
use DecoMarketing\Services\Coupons;
use DecoMarketing\Services\Guard;
use DecoMarketing\Services\Health;
use DecoMarketing\Services\Management;
use DecoMarketing\Services\PopupAnalytics;
use DecoMarketing\Services\Reports;
use DecoMarketing\Services\Shopify;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WorkspaceController
{
    public function entry(Request $request)
    {
        $store = app(CurrentStore::class)->require();
        app(Guard::class)->actor($store, $request->user());

        return redirect()->route('marketing.index', [$store->organization_id, $store->id]);
    }

    private function authorize(Request $request, Organization $organization, Store $store, bool $write = false): void
    {
        abort_unless($organization->id === $store->organization_id, 403);
        app(Guard::class)->actor($store, $request->user(), $write);
    }

    public function index(Request $request, Organization $organization, Store $store)
    {
        $this->authorize($request, $organization, $store);
        // Rebuild shared navigation after store.access resolves the explicit route store.
        Inertia::share(app(\App\Http\Middleware\HandleInertiaRequests::class)->share($request));
        $v = $request->validate(['tab' => 'nullable|in:overview,flows,campaigns,templates,contacts,waitlist,popup,logs,health,attribution', 'flow' => 'nullable|in:welcome,abandoned,payment,back_in_stock,cancelled,advocacy,campaign,test', 'q' => 'nullable|string|max:254', 'status' => 'nullable|string|max:40', 'page' => 'nullable|integer|min:1|max:100000', 'days' => 'nullable|integer|in:7,30,90']);
        $tab = $v['tab'] ?? 'overview';
        $settings = Settings::forStore($store)->first();
        $rows = null;
        $q = $v['q'] ?? '';
        $status = $v['status'] ?? '';
        $flowFilter = $v['flow'] ?? '';
        if ($tab === 'contacts') {
            $rows = Contact::forStore($store)->when($q, fn ($b) => $b->where('email_hash', Contacts::hash($q)))
                ->when($status, fn ($b) => $status === 'suppressed' ? $b->where('suppressed', true) : $b->where('consent', $status))
                ->latest('id')->paginate(25)->withQueryString()->through(fn ($c) => ['uuid' => $c->uuid, 'email' => $c->email_encrypted, 'name' => $c->name_encrypted, 'source' => $c->source, 'consent' => $c->consent, 'suppressed' => $c->suppressed, 'consent_at' => $c->consent_at?->toIso8601String(), 'orders_count' => $c->orders_count, 'timezone' => $c->timezone, 'created_at' => $c->created_at->toIso8601String()]);
        }
        if ($tab === 'logs') {
            $rows = Delivery::forStore($store)->with('contact', 'enrollment')->when($flowFilter, fn ($b) => $flowFilter === 'test' ? $b->whereIn('kind', ['template_test', 'campaign_test']) : $b->where('kind', 'automation')->whereHas('enrollment', fn ($e) => $e->where('flow_key', $flowFilter)))->when($status, fn ($b) => $b->where('status', $status))->when($q, fn ($b) => $b->whereHas('contact', fn ($c) => $c->where('email_hash', Contacts::hash($q))))
                ->latest('id')->paginate(25)->withQueryString()->through(fn ($d) => ['uuid' => $d->uuid, 'email' => $d->contact?->email_encrypted, 'flow' => $d->enrollment?->flow_key ?? $d->kind, 'step' => $d->step + 1, 'status' => $d->status, 'reason' => $d->reason, 'subject' => $d->payload_encrypted['subject'] ?? '', 'attempts' => $d->attempts, 'created_at' => $d->created_at->toIso8601String(), 'sent_at' => $d->sent_at?->toIso8601String(), 'opened_at' => $d->opened_at?->toIso8601String(), 'human_opened_at' => $d->human_opened_at?->toIso8601String(), 'clicked_at' => $d->clicked_at?->toIso8601String()]);
        }
        if ($tab === 'waitlist') {
            $rows = Waitlist::forStore($store)->with('contact')->when($status, fn ($b) => $b->where('status', $status))->latest('id')->paginate(25)->withQueryString()->through(fn ($w) => ['uuid' => $w->uuid, 'email' => $w->contact?->email_encrypted, 'sku' => $w->sku, 'product_title' => $w->product_title, 'status' => $w->status, 'notified_at' => $w->notified_at?->toIso8601String()]);
        }
        if ($tab === 'attribution') {
            $rows = app(\DecoMarketing\Services\AttributionRows::class)->page($store, (int) ($v['days'] ?? 30));
        }
        if ($tab === 'campaigns') {
            $rows = Campaign::forStore($store)->latest('id')->paginate(25)->withQueryString();
        }
        $stats = [
            'contacts' => Contact::forStore($store)->count(), 'subscribed' => Contact::forStore($store)->where('consent', 'subscribed')->where('suppressed', false)->count(),
            'sent' => Delivery::forStore($store)->whereNotNull('sent_at')->count(), 'simulated' => Delivery::forStore($store)->where('status', 'simulated')->count(),
            'active' => Enrollment::forStore($store)->where('status', 'active')->count(), 'held' => Delivery::forStore($store)->whereIn('status', ['uncertain', 'held'])->count(),
            'failed' => Delivery::forStore($store)->where('status', 'failed')->count(),
        ];

        return Inertia::render('Marketing/Index', [
            'organization' => $organization->only(['id', 'name']), 'store' => $store->only(['id', 'name', 'shopify_domain', 'currency']),
            'tab' => $tab, 'settings' => $settings?->only(['enabled', 'daily_limit', 'frequency_hours', 'timezone', 'popup', 'cutover_at', 'warmup_enabled', 'warmup_steps', 'coupons', 'welcome_coupon']),
            'transport' => app(Guard::class)->previewOnly($store) ? 'preview' : config('marketing.transport'), 'connected' => app(Shopify::class)->installation($store) !== null, 'canManage' => $request->user()->hasPermission('marketing.manage', $organization, $store),
            'stats' => $stats, 'rows' => $rows, 'filters' => ['q' => $q, 'status' => $status, 'flow' => $flowFilter],
            'conversion' => $tab === 'overview' ? app(ConversionReports::class)->report($store) : null,
            'overview' => in_array($tab, ['overview', 'flows']) ? app(Reports::class)->overview($store) : null,
            'campaignMetrics' => $tab === 'campaigns' ? app(Reports::class)->campaignMetrics($store, $rows->getCollection()->pluck('id')->all()) : [],
            'templateMetrics' => $tab === 'templates' ? Delivery::forStore($store)->where('kind', 'automation')->where('sent_at', '>=', now()->subDays(30))->whereNotNull('template_key')->selectRaw('template_key, count(*) as total')->groupBy('template_key')->pluck('total', 'template_key') : [],
            'flows' => in_array($tab, ['overview', 'flows', 'templates']) ? Flow::forStore($store)->orderBy('id')->get() : [],
            'templates' => $tab === 'templates' ? Template::forStore($store)->orderBy('id')->get()->map(fn ($t) => [...$t->toArray(), 'is_customized' => $t->published !== null && $t->published != app(Catalog::class)->defaultContent($t->key), 'default_available' => app(Catalog::class)->defaultContent($t->key) !== null]) : [],
            'health' => $tab === 'health' ? [...app(Health::class)->report($store), 'coupons' => app(Coupons::class)->report($store)] : null,
            'popupStats' => $tab === 'popup' ? app(PopupAnalytics::class)->report($store, (int) ($v['days'] ?? 30)) : null,
        ]);
    }

    public function change(Request $request, Organization $organization, Store $store, string $action)
    {
        $this->authorize($request, $organization, $store, true);
        $message = app(Management::class)->change($store, $request->user(), $action, $request->all());

        return back()->with('success', $message);
    }

    public function previewImport(Request $request, Organization $organization, Store $store)
    {
        $this->authorize($request, $organization, $store, true);

        return response()->json(app(Management::class)->importPreview($store, $request->user(), $request->all()));
    }

    public function export(Request $request, Organization $organization, Store $store)
    {
        $this->authorize($request, $organization, $store, true);
        AuditLog::create(['organization_id' => $organization->id, 'store_id' => $store->id, 'user_id' => $request->user()->id, 'action' => 'marketing.contacts.export', 'metadata' => ['scope' => 'store']]);

        return response()->streamDownload(function () use ($store) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'name', 'consent', 'consent_at', 'suppressed', 'source'], ',', '"', '');
            Contact::forStore($store)->orderBy('id')->chunkById(250, function ($contacts) use ($out) {
                foreach ($contacts as $c) {
                    $row = [$c->email_encrypted, $c->name_encrypted ?? '', $c->consent, $c->consent_at?->toIso8601String() ?? '', $c->suppressed ? 'true' : 'false', $c->source];
                    $row = array_map(fn ($value) => preg_match('/^[=+@\-\t\r]/', $value) ? "'".$value : $value, $row);
                    fputcsv($out, $row, ',', '"', '');
                }
            });
            fclose($out);
        }, 'marketing-test-contacts.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
