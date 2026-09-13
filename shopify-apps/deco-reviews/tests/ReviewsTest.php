<?php

namespace Tests\DecoReviews;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use DecoReviews\Controllers\StorefrontController;
use DecoReviews\Models\ImportBatch;
use DecoReviews\Models\Invitation;
use DecoReviews\Models\Review;
use DecoReviews\Models\Settings;
use DecoReviews\Services\ImportService;
use DecoReviews\Services\InvitationService;
use DecoReviews\Services\ReviewService;
use DecoReviews\Services\VideoInspector;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class ReviewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $config = require base_path('shopify-apps/deco-reviews/config/deco_reviews.php');
        config(['deco_reviews' => $config]);
    }

    private function context(string $suffix = 'one', bool $superAdmin = true): array
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'metadata' => $superAdmin ? ['is_super_admin' => true] : [],
        ]);
        $organization = Organization::create([
            'name' => 'Deco Reviews '.$suffix,
            'code' => 'deco-reviews-'.$suffix.'-'.Str::lower(Str::random(5)),
            'status' => 'active',
        ]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create([
            'name' => 'Reviews '.$suffix,
            'shopify_domain' => 'deco-reviews-'.$suffix.'-'.Str::lower(Str::random(5)).'.myshopify.com',
            'status' => 'active',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);

        return [$user, $organization, $store];
    }

    private function product(Store $store, string $handle = 'road-bike'): Product
    {
        return Product::create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'shopify_product_id' => (string) random_int(100000, 999999),
            'title' => Str::headline($handle),
            'handle' => $handle,
            'status' => 'active',
            'synced_at' => now(),
        ]);
    }

    private function reviewInput(Product $product, array $replace = []): array
    {
        return array_replace([
            'kind' => 'product',
            'product_id' => $product->id,
            'author_name' => 'Taylor Rider',
            'author_email' => 'taylor@example.test',
            'rating' => 5,
            'title' => 'A reliable ride',
            'body' => 'Comfortable and stable on long rides.',
        ], $replace);
    }

    private function order(Store $store, Product $product, array $replace = []): Order
    {
        $order = Order::create(array_replace([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'shopify_order_id' => random_int(1000000, 9999999),
            'order_number' => '#1001',
            'email' => 'buyer@example.test',
            'financial_status' => 'paid',
            'fulfillment_status' => 'fulfilled',
            'currency' => 'USD',
            'total_price' => 100,
            'subtotal_price' => 90,
            'total_tax' => 10,
            'processed_at' => now()->subDays(20),
            'created_at_shopify' => now()->subDays(20),
            'synced_at' => now(),
        ], $replace));
        OrderItem::create([
            'order_id' => $order->id,
            'shopify_line_item_id' => random_int(1000000, 9999999),
            'product_id' => $product->id,
            'shopify_product_id' => $product->shopify_product_id,
            'title' => $product->title,
            'quantity' => 1,
            'current_quantity' => 1,
            'price' => 90,
        ]);

        return $order;
    }

    private function expectStatus(int $status, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected HTTP {$status}.");
        } catch (HttpExceptionInterface $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    public function test_management_requires_store_membership_and_all_three_permissions(): void
    {
        [$user, $organization, $store] = $this->context('rbac', false);
        $this->seed(PermissionSeeder::class);
        $role = Role::create(['organization_id' => $organization->id, 'name' => 'Reviews reader', 'slug' => 'reviews-reader', 'is_system' => false]);
        $role->permissions()->sync(Permission::whereIn('slug', ['apps.view', 'products.view'])->pluck('id'));
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => $store->id]);

        $service = app(ReviewService::class);
        $service->authorize($user, $store);
        $this->expectStatus(403, fn () => $service->authorize($user, $store, true));

        $role->permissions()->syncWithoutDetaching(Permission::where('slug', 'products.update')->value('id'));
        $service->authorize($user, $store, true);

        [, , $foreignStore] = $this->context('foreign');
        $this->expectStatus(403, fn () => $service->authorize($user, $foreignStore));
    }

    public function test_product_review_cannot_reference_another_store_or_organization(): void
    {
        [$user, , $store] = $this->context();
        [, , $otherStore] = $this->context('other');
        $foreignProduct = $this->product($otherStore, 'foreign-bike');

        try {
            app(ReviewService::class)->create($store, $this->reviewInput($foreignProduct), [], $user);
            $this->fail('A foreign product was accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('product_id', $error->errors());
        }
        $this->assertDatabaseCount('deco_reviews', 0);
    }

    public function test_moderation_cannot_cross_store_and_bulk_is_all_or_none(): void
    {
        [$user, , $store] = $this->context();
        [, , $otherStore] = $this->context('other');
        $local = app(ReviewService::class)->create($store, $this->reviewInput($this->product($store)), [], $user);
        $foreign = app(ReviewService::class)->create($otherStore, $this->reviewInput($this->product($otherStore), ['author_email' => 'other@example.test']), [], User::where('email', '!=', $user->email)->latest('id')->firstOrFail());

        $this->expectStatus(404, fn () => app(ReviewService::class)->moderate($store, $user, [$foreign->uuid], ['status' => 'published']));
        $this->expectStatus(404, fn () => app(ReviewService::class)->moderate($store, $user, [$local->uuid, $foreign->uuid], ['status' => 'published']));
        $this->assertSame('pending', $local->fresh()->status);
        $this->assertSame('pending', $foreign->fresh()->status);
    }

    public function test_auto_publish_treats_low_and_high_ratings_identically(): void
    {
        [$user, , $store] = $this->context();
        $product = $this->product($store);
        Settings::create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'values' => array_replace(config('deco_reviews.defaults'), ['auto_publish_days' => 0])]);
        $low = app(ReviewService::class)->create($store, $this->reviewInput($product, ['rating' => 1, 'body' => 'Bad fit.']), [], $user);
        $high = app(ReviewService::class)->create($store, $this->reviewInput($product, ['rating' => 5, 'body' => 'Great fit.']), [], $user);
        $this->assertSame('published', $low->status);
        $this->assertSame('published', $high->status);
        $this->assertNotNull($low->published_at);
        $this->assertNotNull($high->published_at);
    }

    public function test_pending_review_is_not_public_and_email_is_encrypted_and_never_serialized_publicly(): void
    {
        [$user, , $store] = $this->context();
        $review = app(ReviewService::class)->create($store, $this->reviewInput($this->product($store)), [], $user);
        $this->assertSame('pending', $review->status);
        $raw = DB::table('deco_reviews')->where('id', $review->id)->first();
        $this->assertNotSame('taylor@example.test', $raw->author_email);
        $this->assertSame('taylor@example.test', $review->fresh()->author_email);
        $public = app(ReviewService::class)->serialize($review->fresh()->load(['product', 'media']), $store, false);
        $this->assertArrayNotHasKey('author_email', $public);
        $this->assertArrayNotHasKey('email_hash', $public);
        $this->assertStringNotContainsString('taylor@example.test', json_encode($public));

        Settings::create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'values' => array_replace(config('deco_reviews.defaults'), ['enabled' => true]),
        ]);
        $feed = app(StorefrontController::class)->data($store, request());
        $this->assertSame([], $feed['data']);
        $this->assertSame(0, $feed['summary']['count']);
        $this->assertStringNotContainsString('taylor@example.test', json_encode($feed));
    }

    public function test_duplicate_review_create_is_idempotent_per_store(): void
    {
        [$user, , $store] = $this->context();
        $input = $this->reviewInput($this->product($store));
        $first = app(ReviewService::class)->create($store, $input, [], $user);
        $second = app(ReviewService::class)->create($store, $input, [], $user);
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('deco_reviews', 1);
    }

    public function test_import_reports_stable_error_codes_deduplicates_and_can_be_undone_within_seven_days(): void
    {
        [$user, , $store] = $this->context();
        $product = $this->product($store);
        $csv = implode("\n", [
            'product_handle,rating,author_name,body,reviewed_at,author_email,title',
            "{$product->handle},5,Ada,Excellent ride,2026-09-01,ada@example.test,Excellent",
            "{$product->handle},5,Ada,Excellent ride,2026-09-01,ada@example.test,Excellent",
            'missing,4,Lin,Good ride,2026-09-01,lin@example.test,Good',
            "{$product->handle},9,Jo,Invalid score,2026-09-01,jo@example.test,Invalid",
            'too,few,columns',
        ]);
        $file = UploadedFile::fake()->createWithContent('reviews.csv', $csv);
        $batch = app(ImportService::class)->import($store, $user, $file);

        $this->assertSame(1, $batch->imported);
        $this->assertSame(1, $batch->skipped);
        $this->assertSame(['PRODUCT_NOT_FOUND', 'INVALID_FIELDS', 'COLUMN_COUNT'], collect($batch->errors)->pluck('code')->all());
        $same = app(ImportService::class)->import($store, $user, UploadedFile::fake()->createWithContent('same.csv', $csv));
        $this->assertSame($batch->id, $same->id);

        app(ImportService::class)->undo($store, $user, $batch->uuid);
        $this->assertSame('undone', $batch->fresh()->status);
        $this->assertSame('unpublished', Review::where('import_id', $batch->id)->firstOrFail()->status);
    }

    public function test_import_older_than_seven_days_cannot_be_undone(): void
    {
        [$user, , $store] = $this->context();
        $batch = ImportBatch::create([
            'uuid' => (string) Str::uuid(), 'organization_id' => $store->organization_id, 'store_id' => $store->id,
            'user_id' => $user->id, 'digest' => hash('sha256', 'old'), 'created_at' => now()->subDays(8), 'updated_at' => now()->subDays(8),
        ]);
        $this->expectException(ValidationException::class);
        app(ImportService::class)->undo($store, $user, $batch->uuid);
    }

    public function test_invitation_requires_scoped_valid_order_and_is_idempotent(): void
    {
        [$user, , $store] = $this->context();
        $product = $this->product($store);
        $order = $this->order($store, $product);
        $invites = app(InvitationService::class);
        $this->assertSame(1, $invites->schedule($store, $user, $order->id));
        $this->assertSame(0, $invites->schedule($store, $user, $order->id));
        $this->assertDatabaseCount('deco_review_invitations', 1);
        $this->assertSame('verification_required', Invitation::firstOrFail()->status);

        [, , $foreignStore] = $this->context('other-order');
        $foreignOrder = $this->order($foreignStore, $this->product($foreignStore));
        try {
            $invites->schedule($store, $user, $foreignOrder->id);
            $this->fail('A foreign order was accepted.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('deco_review_invitations', 1);
        }
    }

    public function test_cancelled_or_refunded_order_cannot_be_invited(): void
    {
        [$user, , $store] = $this->context();
        $product = $this->product($store);
        foreach ([['cancelled_at' => now()], ['financial_status' => 'refunded']] as $index => $state) {
            $order = $this->order($store, $product, array_replace(['shopify_order_id' => 99000 + $index, 'order_number' => '#'.(2000 + $index)], $state));
            try {
                app(InvitationService::class)->schedule($store, $user, $order->id);
                $this->fail('Invalid order was invited.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('order_id', $error->errors());
            }
        }
        $this->assertDatabaseCount('deco_review_invitations', 0);
    }

    public function test_signed_buyer_submission_is_bound_to_invitation_identity_and_product(): void
    {
        [, , $store] = $this->context();
        $product = $this->product($store);
        $other = $this->product($store, 'other-bike');
        $order = $this->order($store, $product);
        $service = app(ReviewService::class);
        $invitation = Invitation::create([
            'uuid' => (string) Str::uuid(), 'organization_id' => $store->organization_id, 'store_id' => $store->id,
            'order_id' => $order->id, 'product_id' => $product->id, 'email' => $order->email,
            'email_hash' => $service->emailHash($store, $order->email), 'status' => 'scheduled',
            'expires_at' => now()->addDays(30),
        ]);
        $url = URL::temporarySignedRoute('deco-reviews.write', now()->addMinutes(10), ['invitation' => $invitation->uuid]);
        $response = $this->postJson($url, [
            'consent' => true,
            'kind' => 'store', 'product_id' => $other->id, 'author_name' => 'Verified Buyer',
            'author_email' => 'attacker@example.test', 'rating' => 4, 'body' => 'A genuine submitted review.',
            'order_id' => 999999, 'verified_source' => 'none', 'source' => 'merchant',
        ])->assertCreated()->assertJsonPath('data.status', 'pending');
        $review = Review::where('uuid', $response->json('data.uuid'))->firstOrFail();

        $this->assertSame('product', $review->kind);
        $this->assertSame($product->id, $review->product_id);
        $this->assertSame($order->id, $review->order_id);
        $this->assertSame('buyer@example.test', $review->author_email);
        $this->assertSame('order', $review->verified_source);
        $this->assertSame('email', $review->source);
    }

    public function test_export_neutralizes_spreadsheet_formulas(): void
    {
        [$user, $organization, $store] = $this->context();
        $product = $this->product($store);
        app(ReviewService::class)->create($store, $this->reviewInput($product, [
            'author_name' => '=HYPERLINK("https://invalid.test")',
            'title' => '+SUM(1,1)',
            'body' => '@dangerous formula',
        ]), [], $user);

        $response = $this->actingAs($user)->get("/organizations/{$organization->id}/stores/{$store->id}/deco-reviews/export")->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'+SUM", $csv);
        $this->assertStringContainsString("'@dangerous", $csv);
    }

    public function test_uploaded_image_is_reencoded_without_original_metadata(): void
    {
        [$user, , $store] = $this->context();
        $file = UploadedFile::fake()->image('camera.jpg', 40, 40);
        file_put_contents($file->getRealPath(), 'RAW-EXIF-PRIVATE-MARKER', FILE_APPEND);
        $review = app(ReviewService::class)->create($store, $this->reviewInput($this->product($store), ['body' => 'Photo review.']), [$file], $user);
        $media = $review->media()->firstOrFail();

        $this->assertSame('image/webp', $media->mime);
        $this->assertStringNotContainsString('RAW-EXIF-PRIVATE-MARKER', Storage::disk('local')->get($media->path));
    }

    public function test_video_review_never_auto_publishes_even_when_store_auto_publish_is_immediate(): void
    {
        [$user, , $store] = $this->context();
        Settings::create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'values' => array_replace(config('deco_reviews.defaults'), ['auto_publish_days' => 0])]);
        $video = UploadedFile::fake()->create('review.mp4', 20, 'video/mp4');
        $inspector = Mockery::mock(VideoInspector::class);
        $inspector->shouldReceive('inspect')->once()->with($video)->andReturn([
            'mime' => 'video/mp4', 'container' => 'mp4', 'codec' => 'h264', 'duration' => 3.0, 'width' => 320, 'height' => 180,
        ]);
        app()->instance(VideoInspector::class, $inspector);
        $review = app(ReviewService::class)->create($store, $this->reviewInput($this->product($store), ['body' => 'Video review.']), [$video], $user);

        $this->assertSame('pending', $review->status);
        $this->assertNull($review->publish_at);
        $this->assertNull($review->published_at);
        $this->assertSame('video', $review->media()->firstOrFail()->type);
    }

    public function test_rejected_mixed_media_leaves_no_review_or_file(): void
    {
        [$user, , $store] = $this->context();
        $input = $this->reviewInput($this->product($store));
        try {
            app(ReviewService::class)->create($store, $input, [
                UploadedFile::fake()->image('photo.png', 20, 20),
                UploadedFile::fake()->create('movie.mp4', 20, 'video/mp4'),
            ], $user);
            $this->fail('Mixed media must fail validation.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('media', $error->errors());
        }
        $this->assertSame(0, Review::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_media_byte_ranges_preserve_publication_and_private_authorization(): void
    {
        [$user, $organization, $store] = $this->context();
        Settings::create(['organization_id' => $organization->id, 'store_id' => $store->id,
            'values' => array_replace(config('deco_reviews.defaults'), ['enabled' => true])]);
        $review = app(ReviewService::class)->create($store, $this->reviewInput($this->product($store)),
            [UploadedFile::fake()->image('photo.png', 20, 20)], $user);
        $media = $review->media()->firstOrFail();
        $public = route('deco-reviews.public-media', [$media->uuid]);
        $private = route('deco-reviews.media', [$organization->id, $store->id, $media->uuid]);
        $this->get($public, ['Range' => 'bytes=0-9'])->assertNotFound();
        $this->actingAs($user)->get($private, ['Range' => 'bytes=0-9'])
            ->assertStatus(206)->assertHeader('Content-Length', '10')->assertHeader('X-Content-Type-Options', 'nosniff');
        app(ReviewService::class)->moderate($store, $user, [$review->uuid], ['status' => 'published']);
        $this->get($public, ['Range' => 'bytes=0-9'])->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 0-9/'.$media->size)->assertHeader('Content-Length', '10');
        $this->get($public, ['Range' => 'bytes=99999999-999999999'])->assertStatus(416);
        app(ReviewService::class)->moderate($store, $user, [$review->uuid], ['status' => 'unpublished', 'reason' => 'QA withdrawal']);
        $this->get($public, ['Range' => 'bytes=0-9'])->assertNotFound();
    }

    public function test_invalid_later_image_rolls_back_review_and_already_stored_images(): void
    {
        [$user, , $store] = $this->context();
        $input = $this->reviewInput($this->product($store));
        try {
            app(ReviewService::class)->create($store, $input, [
                UploadedFile::fake()->image('valid.png', 20, 20),
                UploadedFile::fake()->create('invalid.png', 1, 'image/png'),
            ], $user);
            $this->fail('Invalid image bytes must fail validation.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('media', $error->errors());
        }
        $this->assertSame(0, Review::count());
        $this->assertSame(0, \DecoReviews\Models\Media::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_authorized_preview_works_while_public_widget_is_disabled_and_never_exposes_email(): void
    {
        [$user, $organization, $store] = $this->context();
        Settings::create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'values' => array_replace(config('deco_reviews.defaults'), ['enabled' => false, 'auto_publish_days' => 0])]);
        $image = UploadedFile::fake()->image('preview.jpg', 40, 40);
        app(ReviewService::class)->create($store, $this->reviewInput($this->product($store), ['body' => 'Private preview review.']), [$image], $user);

        $response = $this->actingAs($user)->get("/organizations/{$organization->id}/stores/{$store->id}/deco-reviews/preview")->assertOk();
        $response->assertJsonCount(1, 'data');
        $this->assertStringContainsString("/organizations/{$organization->id}/stores/{$store->id}/deco-reviews/media/", $response->json('data.0.media.0.url'));
        $this->assertStringNotContainsString('taylor@example.test', $response->getContent());
    }
}
