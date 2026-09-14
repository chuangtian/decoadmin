<?php

namespace Tests\DecoReviews;

use App\Models\Organization;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use DecoReviews\Controllers\StorefrontController;
use DecoReviews\Models\ReviewGroup;
use DecoReviews\Models\Settings;
use DecoReviews\Services\ProductGroupService;
use DecoReviews\Services\ReviewService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductGroupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['deco_reviews' => require base_path('shopify-apps/deco-reviews/config/deco_reviews.php')]);
    }

    private function context(string $suffix): array
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'metadata' => ['is_super_admin' => true]]);
        $organization = Organization::create([
            'name' => 'Review groups '.$suffix,
            'code' => 'review-groups-'.$suffix.'-'.Str::lower(Str::random(5)),
            'status' => 'active',
        ]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create([
            'name' => 'Group store '.$suffix,
            'shopify_domain' => 'group-'.$suffix.'-'.Str::lower(Str::random(5)).'.myshopify.com',
            'status' => 'active',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);

        return [$user, $organization, $store];
    }

    private function product(Store $store, string $name): Product
    {
        return Product::create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'shopify_product_id' => (string) random_int(100000, 999999),
            'title' => $name,
            'handle' => Str::slug($name),
            'status' => 'active',
            'synced_at' => now(),
        ]);
    }

    private function publishedReview(Store $store, User $user, Product $product, string $email): void
    {
        $review = app(ReviewService::class)->create($store, [
            'kind' => 'product',
            'product_id' => $product->id,
            'author_name' => 'Synthetic Group Buyer',
            'author_email' => $email,
            'rating' => 5,
            'title' => 'Synthetic grouped review',
            'body' => 'Test-only product-group review for '.$product->title,
        ], [], $user);
        $review->update(['status' => 'published', 'published_at' => now()]);
    }

    public function test_active_group_shares_only_published_reviews_and_disabling_restores_individual_feed(): void
    {
        [$user, , $store] = $this->context('feed');
        [$first, $second, $outside] = [
            $this->product($store, 'Group Bike One'),
            $this->product($store, 'Group Bike Two'),
            $this->product($store, 'Outside Bike'),
        ];
        Settings::create(['organization_id' => $store->organization_id, 'store_id' => $store->id,
            'values' => array_replace(config('deco_reviews.defaults'), ['enabled' => true])]);
        $group = app(ProductGroupService::class)->create($store, $user, [
            'name' => 'Bike family',
            'active' => true,
            'product_ids' => [$first->id, $second->id],
        ]);
        $this->publishedReview($store, $user, $first, 'group-one@example.test');
        $this->publishedReview($store, $user, $second, 'group-two@example.test');
        $this->publishedReview($store, $user, $outside, 'outside@example.test');

        $feed = app(StorefrontController::class)->data($store, Request::create('/feed', 'GET', [
            'product_id' => $first->shopify_product_id,
            'kind' => 'product',
        ]));
        $this->assertSame(2, $feed['summary']['count']);
        $this->assertEqualsCanonicalizing(['Group Bike One', 'Group Bike Two'], collect($feed['data'])->pluck('product_title')->all());
        $this->assertCount(1, app(ProductGroupService::class)->listing($store));

        app(ProductGroupService::class)->update($store, $user, $group->uuid, [
            'name' => 'Bike family',
            'active' => false,
            'product_ids' => [$first->id, $second->id],
        ]);
        $individual = app(StorefrontController::class)->data($store, Request::create('/feed', 'GET', [
            'product_id' => $first->shopify_product_id,
            'kind' => 'product',
        ]));
        $this->assertSame(1, $individual['summary']['count']);
        $this->assertSame('Group Bike One', $individual['data'][0]['product_title']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'deco_reviews.group.updated', 'subject_type' => ReviewGroup::class]);
    }

    public function test_group_membership_is_store_scoped_and_a_product_cannot_be_in_two_groups(): void
    {
        [$user, , $store] = $this->context('scope');
        [$first, $second, $third] = [
            $this->product($store, 'Scope Bike One'),
            $this->product($store, 'Scope Bike Two'),
            $this->product($store, 'Scope Bike Three'),
        ];
        $groups = app(ProductGroupService::class);
        $group = $groups->create($store, $user, ['name' => 'First group', 'active' => true, 'product_ids' => [$first->id, $second->id]]);

        try {
            $groups->create($store, $user, ['name' => 'Overlapping group', 'active' => true, 'product_ids' => [$second->id, $third->id]]);
            $this->fail('A product was accepted into two review groups.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('product_ids', $error->errors());
        }
        $this->assertDatabaseCount('deco_review_groups', 1);

        [$foreignUser, , $foreignStore] = $this->context('foreign');
        $foreignProduct = $this->product($foreignStore, 'Foreign Bike');
        $foreignProductTwo = $this->product($foreignStore, 'Foreign Bike Two');
        try {
            $groups->update($store, $user, $group->uuid, ['name' => 'First group', 'active' => true, 'product_ids' => [$first->id, $foreignProduct->id]]);
            $this->fail('A foreign product was accepted into a review group.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('product_ids', $error->errors());
        }
        try {
            $groups->update($foreignStore, $foreignUser, $group->uuid, [
                'name' => 'Foreign update', 'active' => true, 'product_ids' => [$foreignProduct->id, $foreignProductTwo->id],
            ]);
            $this->fail('A group from another tenant was returned.');
        } catch (ModelNotFoundException $error) {
            $this->assertSame(ReviewGroup::class, $error->getModel());
        }
    }
}
