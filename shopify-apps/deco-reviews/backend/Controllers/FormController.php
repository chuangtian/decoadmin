<?php

namespace DecoReviews\Controllers;

use App\Models\Organization;
use App\Models\Product;
use App\Models\Store;
use DecoReviews\Services\FormService;
use DecoReviews\Services\ReviewService;
use Illuminate\Http\Request;

class FormController
{
    private function authorize(Request $request, Organization $organization, Store $store, bool $write = false): void
    {
        abort_unless((int) $store->organization_id === (int) $organization->id, 404);
        app(ReviewService::class)->authorize($request->user(), $store, $write);
    }

    public function save(Request $request, Organization $organization, Store $store, FormService $forms)
    {
        $this->authorize($request, $organization, $store, true);
        $forms->save($store, $request->user(), $request->all());

        return back()->with('success', '新插件的评价表单已保存，已有回答的可见性不会改变。');
    }

    public function preview(Request $request, Organization $organization, Store $store, FormService $forms)
    {
        $this->authorize($request, $organization, $store);
        $values = $request->validate(['kind' => 'nullable|in:product,store', 'product_id' => 'nullable|integer|min:1']);
        $product = isset($values['product_id']) ? Product::where('organization_id', $organization->id)->where('store_id', $store->id)->whereKey($values['product_id'])->firstOrFail() : null;
        $configuration = $product || ($values['kind'] ?? '') === 'store'
            ? $forms->forProduct($store, $values['kind'] ?? 'product', $product?->id) : $forms->configuration($store);

        return response()->view('deco-reviews::storefront', ['feedUrl' => null, 'submitUrl' => null, 'formPreview' => true,
            'formConfig' => $configuration, 'invitationProduct' => ['title' => $product?->title ?? 'Preview only']])
            ->header('Cache-Control', 'private, no-store');
    }
}
