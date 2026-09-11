<?php

namespace App\Http\Controllers;

use App\Domain\ReferralAffiliate\Services\AffiliateMaterialService;
use App\Domain\ReferralAffiliate\Services\AffiliateNotificationService;
use App\Models\Organization;
use App\Models\Store;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AffiliateMaterialController extends Controller
{
    public function index(Request $r, Organization $organization, Store $store, AffiliateMaterialService $service)
    {
        return Inertia::render('Affiliate/Materials', ['organization' => $organization->only('id', 'name'), 'store' => $store->only('id', 'name'),
            'templates' => $r->user()->hasPermission('affiliate.settings.manage', $organization, $store) ? app(AffiliateNotificationService::class)->templates($organization, $store, $r->user()) : [],
            'assets' => $service->listing($organization, $store, $r->user()), 'canManage' => $r->user()->hasPermission('affiliate.promoters.manage', $organization, $store)]);
    }

    public function template(Request $r, Organization $organization, Store $store, string $key)
    {
        $values = $r->validate(['subject' => ['required', 'string', 'max:200', 'regex:/^[^\r\n]+$/'], 'body' => ['required', 'string', 'max:10000'], 'enabled' => ['required', 'boolean']]);
        app(AffiliateNotificationService::class)->save($organization, $store, $r->user(), $key, $values);

        return back()->with('success', '通知模板已保存。');
    }

    public function upload(Request $r, Organization $organization, Store $store, AffiliateMaterialService $service)
    {
        $v = $r->validate(['title' => ['required', 'string', 'max:150', 'regex:/^[^\\\\\\/\\x00-\\x1f]+$/u'], 'file' => ['required', 'file', 'mimes:png,jpg,jpeg,webp,pdf,txt', 'max:10240']]);
        $service->upload($organization, $store, $r->user(), $r->file('file'), $v['title']);

        return back()->with('success', '素材已上传，已批准的推广者可以下载。');
    }

    public function remove(Request $r, Organization $organization, Store $store, string $asset, AffiliateMaterialService $service)
    {
        $service->remove($organization, $store, $r->user(), $asset);

        return back()->with('success','素材已移除。');
    }
}
