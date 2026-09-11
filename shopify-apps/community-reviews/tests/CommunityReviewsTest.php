<?php

namespace Tests\CommunityReviews;

use App\Models\ModelAssetFolder;
use App\Models\ModelAssetImage;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ReputationMention;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use CommunityReviews\Models\Installation;
use CommunityReviews\Models\ModelLink;
use CommunityReviews\Models\Settings;
use CommunityReviews\Services\ReviewFeed;
use CommunityReviews\Services\ReviewManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use CommunityReviews\Services\FeedCache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommunityReviewsTest extends TestCase
{
    use RefreshDatabase;

    private array $live = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::flush();
        config(['community_reviews.environment' => 'test', 'community_reviews.active.client_secret' => 'test-only-secret']);
        Http::fake(fn () => Http::response(['data' => ['nodes' => array_values($this->live)]]));
    }

    private function context(string $suffix = 'one'): array
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::create(['name' => 'Reviews '.$suffix, 'code' => 'reviews-'.$suffix]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create(['name' => 'Reviews '.$suffix, 'shopify_domain' => 'reviews-'.$suffix.'.myshopify.com', 'status' => 'active']);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        Installation::create(['store_id' => $store->id, 'organization_id' => $organization->id, 'environment' => 'test', 'access_token_encrypted' => 'test-token', 'app_installation_id' => 'gid://shopify/AppInstallation/1']);
        Settings::create(['store_id' => $store->id, 'organization_id' => $organization->id, 'enabled' => true, 'heading' => 'Test', 'read_more_url' => 'https://example.com/reviews', 'card_count' => 24]);
        return [$user, $organization, $store];
    }

    private function model(Store $store, User $user, string $label, int $id, bool $hasImage = true): ModelLink
    {
        $product = Product::create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'shopify_product_id' => (string) $id, 'title' => $label, 'handle' => strtolower(str_replace(' ', '-', $label)), 'status' => 'active', 'synced_at' => now()]);
        $folder = ModelAssetFolder::create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'created_by' => $user->id, 'name' => $label]);
        if ($hasImage) {
            $path = 'test-assets/'.Str::uuid().'.webp';
            Storage::disk('local')->put($path, 'test-image-bytes-'.$label);
            ModelAssetImage::create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'folder_id' => $folder->id, 'uploaded_by' => $user->id, 'disk' => 'local', 'path' => $path, 'thumbnail_path' => $path, 'mime_type' => 'image/webp', 'original_name' => 'test.webp', 'byte_size' => 16, 'width' => 640, 'height' => 520]);
        }
        $this->live[$id] = ['id' => 'gid://shopify/Product/'.$id, 'title' => $label, 'handle' => $product->handle, 'status' => 'ACTIVE', 'publishedAt' => now()->subDay()->toIso8601String(), 'onlineStoreUrl' => null, 'featuredImage' => ['url' => 'https://cdn.shopify.com/test.webp']];
        return ModelLink::create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'folder_id' => $folder->id, 'product_id' => $product->id, 'label' => $label, 'series' => 'TEST', 'aliases' => [], 'enabled' => true]);
    }

    private function review(Store $store, string $content, int $rating = 5, ?string $model = null): ReputationMention
    {
        return ReputationMention::create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'origin' => 'manual', 'source' => 'trustpilot', 'canonical_key' => (string) Str::uuid(), 'reviewer_name' => 'Test Reviewer', 'content' => $content, 'rating' => $rating, 'model_name' => $model, 'is_active' => true, 'synced_at' => now()]);
    }

    public function test_ratings_and_models_are_matched_and_unpublished_empty_or_foreign_models_are_excluded(): void
    {
        [$user, , $store] = $this->context();
        for ($i = 0; $i < 4; $i++) {
            ModelAssetFolder::create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'created_by' => $user->id, 'name' => 'Unlinked folder '.$i]);
        }
        $x1 = $this->model($store, $user, 'X1', 101);
        $x1s = $this->model($store, $user, 'X1S', 102);
        $this->model($store, $user, 'X2 Pro', 103, false);
        $this->model($store, $user, 'X7', 104);
        $this->live[104]['status'] = 'DRAFT';
        $named = $this->review($store, 'My X1S is excellent.', 4);
        $generic = $this->review($store, 'Very comfortable bike.', 5);
        $this->review($store, 'Three stars.', 3);
        $this->review($store, 'The X7 is great.', 5);
        $this->review($store, 'The X2Pro is great.', 5);
        $this->review($store, 'Old model is great.', 5, 'UNKNOWN');
        [$otherUser, , $other] = $this->context('two');
        $this->model($other, $otherUser, 'Foreign', 105);
        $this->review($other, 'Foreign store review.', 5);
        $feed = app(ReviewFeed::class)->build($store);
        $this->assertCount(2, $feed['cards']);
        $cards = collect($feed['cards'])->keyBy('id');
        $this->assertSame(4, $cards[$named->uuid]['rating']);
        $this->assertSame('X1S', $cards[$named->uuid]['product']['label']);
        $this->assertSame('/products/x1s', $cards[$named->uuid]['product']['url']);
        foreach ($feed['cards'] as $card) {
            $this->assertSame($card['image']['alt'], $card['product']['label']);
            $this->assertContains($card['product']['label'], ['X1', 'X1S']);
        }
        $this->assertTrue($cards->has($generic->uuid));
        $this->assertSame('https://example.com/reviews', $feed['read_more_url']);
        $this->assertStringNotContainsString('test-token', json_encode($feed));
        $preview = collect(app(ReviewFeed::class)->build($store, true)['cards'])->keyBy('id');
        $this->assertSame('https://'.$store->shopify_domain.'/products/x1s', $preview[$named->uuid]['product']['url']);
    }

    public function test_proxy_signature_and_tenant_selection_cannot_be_spoofed(): void
    {
        [$user, , $store] = $this->context();
        $this->model($store, $user, 'X7', 101);
        $this->review($store, 'My X7 rides well. Contact test@example.com.', 5);
        $this->getJson('/api/shopify-app/community-reviews/proxy/feed?shop='.$store->shopify_domain)->assertUnauthorized();
        $params = ['shop' => $store->shopify_domain, 'timestamp' => now()->timestamp, 'store_id' => 99999, 'path_prefix' => '/apps/community-reviews'];
        $parts = [];
        foreach ($params as $key => $value) $parts[] = $key.'='.$value;
        sort($parts, SORT_STRING);
        $params['signature'] = hash_hmac('sha256', implode('', $parts), 'test-only-secret');
        $response = $this->getJson('/api/shopify-app/community-reviews/proxy/feed?'.http_build_query($params))->assertOk()->assertJsonCount(1, 'data.cards');
        $this->assertStringNotContainsString('test@example.com', $response->getContent());
        $this->postJson('/api/shopify-app/community-reviews/bootstrap?shop='.$store->shopify_domain)->assertUnauthorized();
    }

    public function test_viewer_cannot_save_and_cross_store_folder_cannot_be_linked(): void
    {
        [$user, $organization, $store] = $this->context();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $role = Role::whereBelongsTo($organization)->where('slug', 'viewer')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([
            Permission::query()->where('slug', 'apps.view')->firstOrFail()->getKey(),
        ]);
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);
        $this->actingAs($user)->putJson(route('community-reviews.save', [$organization, $store]), [])->assertForbidden();
        $this->actingAs($user)->get(route('community-reviews.index', [$organization, $store]))->assertOk();
        [$otherUser, $otherOrg, $other] = $this->context('two');
        $this->actingAs($user)->get(route('community-reviews.index', [$otherOrg, $other]))->assertForbidden();
    }

    public function test_apps_permission_is_required_to_view_management_page(): void
    {
        [$user, $organization, $store] = $this->context();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $role = Role::whereBelongsTo($organization)->where('slug', 'viewer')->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);

        $this->actingAs($user)
            ->get(route('community-reviews.index', [$organization, $store]))
            ->assertForbidden();
    }

    private function extraImage(ModelLink $link, string $bytes): ModelAssetImage
    {
        $original = $link->folder->images()->firstOrFail();
        $image = $original->replicate(['uuid']);
        $image->uuid = (string) Str::uuid();
        $image->path = $image->thumbnail_path = 'test-assets/'.$image->uuid.'.webp';
        Storage::disk('local')->put($image->path, $bytes);
        $image->save();
        return $image;
    }

    public function test_duplicate_content_and_duplicate_uploaded_images_never_share_a_batch(): void
    {
        [$user, , $store] = $this->context();
        $link = $this->model($store, $user, 'X7', 101);
        $this->extraImage($link, 'test-image-bytes-X7');
        $this->extraImage($link, 'another-image');
        $this->review($store, 'Great X7 ride.');
        $this->review($store, '  GREAT   X7 ride. ');
        $this->review($store, 'Comfortable X7 seat.');
        $this->review($store, 'Another X7 review.');
        $cards = app(ReviewFeed::class)->build($store)['cards'];
        $this->assertCount(2, $cards);
        $hashes = [];
        foreach ($cards as $card) {
            $image = ModelAssetImage::where('uuid', $card['image']['id'])->firstOrFail();
            $hashes[] = hash('sha256', Storage::disk('local')->get($image->thumbnail_path));
        }
        $this->assertCount(2, array_unique($hashes));
    }

    public function test_refresh_prioritizes_unseen_reviews_and_images_then_reuses_without_batch_duplicates(): void
    {
        [$user, , $store] = $this->context();
        $link = $this->model($store, $user, 'X7', 101);
        for ($i = 1; $i < 8; $i++) $this->extraImage($link, 'unique-image-'.$i);
        for ($i = 0; $i < 8; $i++) $this->review($store, 'X7 review number '.$i);
        Settings::where('store_id', $store->id)->first()->update(['card_count' => 4]);
        $visitor = (string) Str::uuid();
        $feed = app(ReviewFeed::class);
        $first = $feed->build($store, visitor: $visitor)['cards'];
        $second = $feed->build($store, visitor: $visitor)['cards'];
        $third = $feed->build($store, visitor: $visitor)['cards'];
        $this->assertCount(4, $first);
        $this->assertCount(4, $second);
        $this->assertSame([], array_values(array_intersect(array_column($first, 'id'), array_column($second, 'id'))));
        $this->assertSame([], array_values(array_intersect(array_column(array_column($first, 'image'), 'id'), array_column(array_column($second, 'image'), 'id'))));
        $this->assertCount(4, array_unique(array_column($third, 'id')));
        $this->assertCount(4, array_unique(array_column(array_column($third, 'image'), 'id')));
    }

    public function test_refresh_uses_other_models_before_recycling_popular_model_images(): void
    {
        [$user, , $store] = $this->context();
        $popular = $this->model($store, $user, 'X1S', 101);
        $other = $this->model($store, $user, 'X7', 102);
        for ($i = 1; $i < 5; $i++) $this->extraImage($other, 'other-image-'.$i);
        for ($i = 0; $i < 30; $i++) $this->review($store, 'X1S popular review '.$i);
        for ($i = 0; $i < 10; $i++) $this->review($store, 'X7 other review '.$i);
        Settings::where('store_id', $store->id)->first()->update(['card_count' => 2]);
        $feed = app(ReviewFeed::class);
        for ($run = 0; $run < 5; $run++) {
            $visitor = (string) Str::uuid();
            $cards = [];
            for ($batch = 0; $batch < 3; $batch++) {
                $next = $feed->build($store, visitor: $visitor)['cards'];
                $this->assertCount(2, $next);
                $cards = [...$cards, ...$next];
            }
            $this->assertCount(6, array_unique(array_column($cards, 'id')));
            $this->assertCount(6, array_unique(array_column(array_column($cards, 'image'), 'id')));
            foreach ($cards as $card) $this->assertSame($card['image']['alt'], $card['product']['label']);
        }
    }

    public function test_cache_ttls_and_model_edits_invalidate_without_leaking_between_stores(): void
    {
        [$user, , $store] = $this->context();
        $this->model($store, $user, 'X7', 101);
        $review = $this->review($store, 'Old X7 content.');
        $feed = app(ReviewFeed::class);
        $this->assertCount(1, $feed->build($store)['cards']);
        $feed->build($store);
        Http::assertSentCount(1);
        $this->live[101]['status'] = 'DRAFT';
        $this->assertCount(1, $feed->build($store)['cards']);
        $this->travel(31)->seconds();
        $this->assertCount(0, $feed->build($store)['cards']);
        $this->live[101]['status'] = 'ACTIVE';
        $review->update(['content' => 'Updated X7 content.']);
        $this->assertSame('Updated X7 content.', $feed->build($store)['cards'][0]['content']);
        $review->update(['is_active' => false]);
        $this->assertCount(0, $feed->build($store)['cards']);

        $loads = 0;
        $cache = app(FeedCache::class);
        $load = function () use (&$loads) { return ['load' => ++$loads]; };
        $this->assertSame(['load' => 1], $cache->remember($store, 'ttl-check', 300, $load));
        $this->travel(299)->seconds();
        $this->assertSame(['load' => 1], $cache->remember($store, 'ttl-check', 300, $load));
        $this->travel(2)->seconds();
        $this->assertSame(['load' => 2], $cache->remember($store, 'ttl-check', 300, $load));
        [, , $other] = $this->context('two');
        $this->assertSame(['load' => 3], $cache->remember($other, 'ttl-check', 300, $load));
        $this->assertSame(['load' => 2], $cache->remember($store, 'ttl-check', 300, $load));
    }

    public function test_signed_product_webhook_invalidates_availability_immediately(): void
    {
        [$user, , $store] = $this->context();
        $this->model($store, $user, 'X7', 101);
        $this->review($store, 'Great X7.');
        $feed = app(ReviewFeed::class);
        $this->assertCount(1, $feed->build($store)['cards']);
        $this->live[101]['status'] = 'DRAFT';
        $body = '{"id":101}';
        $signature = base64_encode(hash_hmac('sha256', $body, 'test-only-secret', true));
        $this->call('POST', '/api/shopify-app/community-reviews/webhooks', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_SHOPIFY_HMAC_SHA256' => $signature,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $store->shopify_domain, 'HTTP_X_SHOPIFY_TOPIC' => 'products/update',
        ], $body)->assertOk();
        $this->assertCount(0, $feed->build($store)['cards']);
    }
}
