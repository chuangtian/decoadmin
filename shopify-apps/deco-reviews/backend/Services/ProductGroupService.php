<?php

namespace DecoReviews\Services;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use DecoReviews\Models\ReviewGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductGroupService
{
    public function __construct(private ReviewService $reviews) {}

    public function listing(Store $store): Collection
    {
        return ReviewGroup::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->with(['products' => fn ($query) => $query->where('products.organization_id', $store->organization_id)
                ->where('products.store_id', $store->id)->orderBy('title')])->orderByDesc('active')->orderBy('name')->get()
            ->map(fn (ReviewGroup $group) => [
                'uuid' => $group->uuid,
                'name' => $group->name,
                'active' => $group->active,
                'product_ids' => $group->products->pluck('id')->all(),
                'products' => $group->products->map->only(['id', 'title'])->values()->all(),
            ]);
    }

    public function create(Store $store, User $user, array $input): ReviewGroup
    {
        $this->reviews->authorize($user, $store, true);
        $data = $this->validated($store, $input);

        return DB::transaction(function () use ($store, $user, $data) {
            $products = $this->lockedProducts($store, $data['product_ids']);
            $this->assertAvailable($store, $products->pluck('id')->all());
            $group = ReviewGroup::create([
                'uuid' => (string) Str::uuid(),
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'name' => $data['name'],
                'active' => $data['active'],
            ]);
            $this->syncProducts($group, $store, $products->pluck('id')->all());
            $this->audit($store, $user, $group, 'created', count($data['product_ids']));

            return $group;
        });
    }

    public function update(Store $store, User $user, string $uuid, array $input): ReviewGroup
    {
        $this->reviews->authorize($user, $store, true);
        $data = $this->validated($store, $input, $uuid);

        return DB::transaction(function () use ($store, $user, $uuid, $data) {
            $group = ReviewGroup::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)
                ->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $products = $this->lockedProducts($store, $data['product_ids']);
            $this->assertAvailable($store, $products->pluck('id')->all(), $group->id);
            $group->update(['name' => $data['name'], 'active' => $data['active']]);
            $this->syncProducts($group, $store, $products->pluck('id')->all());
            $this->audit($store, $user, $group, 'updated', count($data['product_ids']));

            return $group;
        });
    }

    public function sharedProductIds(Store $store, int $productId): array
    {
        $groupId = DB::table('deco_review_group_products as member')
            ->join('deco_review_groups as groups', 'groups.id', '=', 'member.group_id')
            ->where('member.organization_id', $store->organization_id)->where('member.store_id', $store->id)
            ->where('member.product_id', $productId)->where('groups.organization_id', $store->organization_id)
            ->where('groups.store_id', $store->id)->where('groups.active', true)->value('groups.id');
        if (! $groupId) {
            return [$productId];
        }

        return DB::table('deco_review_group_products')->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)->where('group_id', $groupId)->orderBy('product_id')->pluck('product_id')->map(fn ($id) => (int) $id)->all();
    }

    private function validated(Store $store, array $input, ?string $ignoreUuid = null): array
    {
        $input['name'] = trim((string) ($input['name'] ?? ''));

        return Validator::make($input, [
            'name' => ['required', 'string', 'max:120', Rule::unique('deco_review_groups', 'name')->where('store_id', $store->id)->ignore($ignoreUuid, 'uuid')],
            'active' => 'required|boolean',
            'product_ids' => 'required|array|min:2|max:100',
            'product_ids.*' => 'required|integer|min:1|distinct',
        ])->validate();
    }

    private function lockedProducts(Store $store, array $ids): Collection
    {
        $products = Product::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->whereIn('id', $ids)->lockForUpdate()->get(['id']);
        if ($products->count() !== count($ids)) {
            throw ValidationException::withMessages(['product_ids' => '所选商品包含无权访问或不存在的记录。']);
        }

        return $products;
    }

    private function assertAvailable(Store $store, array $ids, ?int $ignoreGroupId = null): void
    {
        $query = DB::table('deco_review_group_products')->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)->whereIn('product_id', $ids);
        if ($ignoreGroupId) {
            $query->where('group_id', '!=', $ignoreGroupId);
        }
        if ($query->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['product_ids' => '每个商品只能属于一个评价共享组，请先从原组移除。']);
        }
    }

    private function syncProducts(ReviewGroup $group, Store $store, array $ids): void
    {
        $pivot = collect($ids)->mapWithKeys(fn ($id) => [$id => [
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
        ]])->all();
        $group->products()->sync($pivot);
    }

    private function audit(Store $store, User $user, ReviewGroup $group, string $event, int $count): void
    {
        AuditLog::create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'user_id' => $user->id,
            'action' => 'deco_reviews.group.'.$event,
            'subject_type' => ReviewGroup::class,
            'subject_id' => $group->id,
            'metadata' => ['active' => $group->active, 'product_count' => $count],
        ]);
    }
}
