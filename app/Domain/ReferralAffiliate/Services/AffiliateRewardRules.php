<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

class AffiliateRewardRules
{
    public function validate(Organization $org, Store $store, User $actor, array $rule): array
    {
        app(AffiliateShopGuard::class)->actor($org, $store, $actor, 'affiliate.programs.manage');
        $v = Validator::make($rule, ['type' => ['required', 'in:percentage,fixed,free_shipping'], 'basis_points' => ['nullable', 'required_if:type,percentage', 'integer', 'between:1,10000'],
            'amount_minor' => ['nullable', 'required_if:type,fixed', 'integer', 'between:1,1000000000'], 'valid_days' => ['required', 'integer', 'between:1,365'],
            'scope' => ['required', 'in:all,product,collection'], 'resource_ids' => ['present', 'array', 'max:20'], 'resource_ids.*' => ['string', 'distinct', 'max:100']])->validate();
        if ($v['type'] === 'free_shipping') {
            $v['scope'] = 'all';
            $v['resource_ids'] = [];
        }
        if ($v['scope'] !== 'all') {
            $type = $v['scope'] === 'product' ? 'Product' : 'Collection';
            $field = $v['scope'] === 'product' ? 'shopify_product_id' : 'shopify_collection_id';
            foreach ($v['resource_ids'] as $id) {
                abort_unless(preg_match('~^gid://shopify/'.$type.'/[0-9]+$~D', $id), 422);
            }
            $rows = app(AffiliateCatalogService::class)->scopedQuery($v['scope'], $org, $store)->whereIn($field, array_map('basename', $v['resource_ids']))->get();
            $found = $rows->count();
            $v['resource_labels'] = $rows->mapWithKeys(fn ($row) => ['gid://shopify/'.$type.'/'.$row->getAttribute($field) => $row->title])->all();
            abort_unless(count($v['resource_ids']) > 0 && $found === count($v['resource_ids']), 422, '奖励范围必须为本店已同步的商品或系列。');
        } else {
            $v['resource_ids'] = [];
        }

        return $v;
    }
}
