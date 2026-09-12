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
use DecoReviews\Models\Invitation;
use DecoReviews\Models\Review;
use DecoReviews\Models\Settings;
use DecoReviews\Services\FormService;
use DecoReviews\Services\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class FormTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $organization;

    private Store $store;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $config = require base_path('shopify-apps/deco-reviews/config/deco_reviews.php');
        config(['deco_reviews' => $config]);
        [$this->user, $this->organization, $this->store] = $this->context('primary');
        $this->product = $this->product($this->store, 'review-bike');
    }

    private function context(string $suffix, bool $superAdmin = true): array
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'metadata' => $superAdmin ? ['is_super_admin' => true] : []]);
        $organization = Organization::create(['name' => 'Form '.$suffix, 'code' => 'form-'.$suffix.'-'.Str::lower(Str::random(5)), 'status' => 'active']);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create(['name' => 'Form '.$suffix, 'shopify_domain' => 'form-'.$suffix.'-'.Str::lower(Str::random(5)).'.myshopify.com', 'status' => 'active']);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);

        return [$user, $organization, $store];
    }

    private function product(Store $store, string $handle): Product
    {
        return Product::create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'shopify_product_id' => (string) random_int(10000, 99999),
            'title' => Str::headline($handle), 'handle' => $handle, 'status' => 'active', 'synced_at' => now()]);
    }

    private function question(array $replace = []): array
    {
        return array_replace(['id' => (string) Str::uuid(), 'label' => 'How was the fit?', 'type' => 'single', 'required' => true,
            'public' => false, 'kind' => 'all', 'product_ids' => [], 'options' => ['Small', 'Perfect', 'Large'], 'min' => 1, 'max' => 5], $replace);
    }

    private function configuration(array $replace = [], array $questions = []): array
    {
        return array_replace(FormService::defaults(), ['questions' => $questions], $replace);
    }

    private function save(array $configuration): array
    {
        app(FormService::class)->save($this->store, $this->user, $configuration);

        return app(FormService::class)->configuration($this->store);
    }

    private function order(Product $product): Order
    {
        $order = Order::create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'shopify_order_id' => '77123',
            'order_number' => '#77123', 'email' => 'buyer@example.test', 'financial_status' => 'paid', 'fulfillment_status' => 'fulfilled',
            'currency' => 'USD', 'total_price' => 100, 'subtotal_price' => 90, 'total_tax' => 10,
            'processed_at' => now(), 'created_at_shopify' => now(), 'synced_at' => now()]);
        OrderItem::create(['order_id' => $order->id, 'shopify_line_item_id' => '88123', 'product_id' => $product->id,
            'shopify_product_id' => $product->shopify_product_id, 'title' => $product->title, 'quantity' => 1, 'current_quantity' => 1, 'price' => 90]);

        return $order;
    }

    private function buyerReview(array $formInput, array $files = []): Review
    {
        $order = Order::first() ?: $this->order($this->product);

        return app(ReviewService::class)->create($this->store, array_merge([
            'kind' => 'product', 'product_id' => $this->product->id, 'author_name' => 'Buyer', 'author_email' => $order->email,
            'rating' => 5, 'title' => 'Review', 'body' => 'A legitimate review.',
        ], $formInput), $files, null, ['source' => 'email', 'verified_source' => 'order', 'order_id' => $order->id]);
    }

    public function test_first_save_replaces_initial_version_and_stale_version_is_rejected(): void
    {
        $this->assertSame('initial', app(FormService::class)->configuration($this->store)['version']);
        $saved = $this->save($this->configuration(['heading' => 'First heading']));
        $this->assertTrue(Str::isUuid($saved['version']));
        $this->assertSame('First heading', $saved['heading']);

        $this->expectException(ValidationException::class);
        app(FormService::class)->save($this->store, $this->user, $this->configuration(['version' => 'initial', 'heading' => 'Stale overwrite']));
    }

    public function test_foreign_product_scope_and_invalid_question_collections_are_rejected(): void
    {
        [, , $foreignStore] = $this->context('foreign');
        $foreign = $this->product($foreignStore, 'foreign-product');
        $missingRequired = $this->question();
        unset($missingRequired['required']);
        foreach ([
            array_fill(0, 11, $this->question()),
            [$duplicate = $this->question(), $duplicate],
            [$this->question(['options' => ['Same', 'Same']])],
            [$this->question(['product_ids' => [$foreign->id]])],
            [$this->question(['type' => 'free_text'])],
            [$missingRequired],
        ] as $questions) {
            try {
                $this->save($this->configuration([], $questions));
                $this->fail('Invalid form questions were accepted.');
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
        }
        $this->assertSame('initial', app(FormService::class)->configuration($this->store)['version']);
    }

    public function test_question_types_and_required_answers_are_strictly_validated(): void
    {
        $single = $this->question();
        $multiple = $this->question(['id' => (string) Str::uuid(), 'label' => 'Choose features', 'type' => 'multiple', 'options' => ['Comfort', 'Speed']]);
        $scale = $this->question(['id' => (string) Str::uuid(), 'label' => 'Score', 'type' => 'scale', 'options' => [], 'min' => 2, 'max' => 8]);
        $saved = $this->save($this->configuration([], [$single, $multiple, $scale]));

        foreach ([
            ['answers' => [], 'form_version' => $saved['version']],
            ['answers' => [$single['id'] => 'Unknown', $multiple['id'] => ['Comfort'], $scale['id'] => 5], 'form_version' => $saved['version']],
            ['answers' => [$single['id'] => 'Perfect', $multiple['id'] => ['Comfort', 'Comfort'], $scale['id'] => 5], 'form_version' => $saved['version']],
            ['answers' => [$single['id'] => 'Perfect', $multiple['id'] => ['Comfort'], $scale['id'] => 9], 'form_version' => $saved['version']],
        ] as $input) {
            try {
                app(FormService::class)->snapshot($this->store, 'product', $this->product->id, $input, []);
                $this->fail('Invalid answers were accepted.');
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
        }
        $snapshot = app(FormService::class)->snapshot($this->store, 'product', $this->product->id, ['form_version' => $saved['version'], 'answers' => [
            $single['id'] => 'Perfect', $multiple['id'] => ['Comfort', 'Speed'], $scale['id'] => 8,
        ]], []);
        $this->assertCount(3, $snapshot);
    }

    public function test_inapplicable_answers_and_missing_saved_form_version_are_rejected(): void
    {
        $question = $this->question(['kind' => 'store']);
        $saved = $this->save($this->configuration([], [$question]));
        foreach ([
            ['answers' => [$question['id'] => 'Perfect'], 'form_version' => $saved['version']],
            ['answers' => []],
        ] as $input) {
            try {
                app(FormService::class)->snapshot($this->store, 'product', $this->product->id, $input, []);
                $this->fail('Invalid form submission was accepted.');
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
        }
    }

    public function test_private_answer_is_encrypted_and_snapshot_survives_later_public_question_edit(): void
    {
        $question = $this->question(['label' => 'Private fit note', 'public' => false]);
        $saved = $this->save($this->configuration([], [$question]));
        $review = $this->buyerReview(['form_version' => $saved['version'], 'answers' => [$question['id'] => 'Perfect']]);
        app(ReviewService::class)->moderate($this->store, $this->user, [$review->uuid], ['status' => 'published']);
        $raw = DB::table('deco_reviews')->where('id', $review->id)->value('form_answers');
        $this->assertStringNotContainsString('Private fit note', $raw);
        $this->assertStringNotContainsString('Perfect', $raw);

        $question['label'] = 'Changed public label';
        $question['public'] = true;
        $this->save($this->configuration(['version' => $saved['version']], [$question]));
        $review = $review->fresh()->load(['product', 'media']);
        $this->assertSame('Private fit note', $review->form_answers[0]['label']);
        $this->assertFalse($review->form_answers[0]['public']);
        $private = app(ReviewService::class)->serialize($review, $this->store, true);
        $public = app(ReviewService::class)->serialize($review, $this->store, false);
        $this->assertSame('Private fit note', $private['form_answers'][0]['label']);
        $this->assertSame([], $public['answers']);
        $this->assertStringNotContainsString('Perfect', json_encode($public));
        Settings::create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id,
            'values' => array_replace(config('deco_reviews.defaults'), ['enabled' => true])]);
        $feed = app(StorefrontController::class)->data($this->store, request());
        $this->assertSame([], $feed['data'][0]['answers']);
        $this->assertStringNotContainsString('Perfect', json_encode($feed));
    }

    public function test_raw_client_snapshot_and_visibility_flags_cannot_be_spoofed(): void
    {
        $question = $this->question(['public' => false]);
        $saved = $this->save($this->configuration([], [$question]));
        $review = $this->buyerReview(['form_version' => $saved['version'], 'answers' => [$question['id'] => 'Perfect'],
            'form_answers' => [['question_id' => 'fake', 'label' => 'Forged', 'value' => 'Public secret', 'public' => true]],
            'public' => true, 'verified_source' => 'none', 'order_id' => 999999]);
        $this->assertCount(1, $review->form_answers);
        $this->assertSame($question['id'], $review->form_answers[0]['question_id']);
        $this->assertFalse($review->form_answers[0]['public']);
        $this->assertSame('order', $review->verified_source);
        $this->assertSame(Order::firstOrFail()->id, $review->order_id);
    }

    public function test_buyer_media_controls_do_not_change_merchant_upload_behavior(): void
    {
        $saved = $this->save($this->configuration(['allow_photos' => false, 'allow_video' => false]));
        try {
            $this->buyerReview(['form_version' => $saved['version'], 'answers' => []], [UploadedFile::fake()->image('buyer.jpg')]);
            $this->fail('Buyer upload bypassed form media controls.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('media', $error->errors());
        }

        $merchant = app(ReviewService::class)->create($this->store, ['kind' => 'product', 'product_id' => $this->product->id,
            'author_name' => 'Merchant import', 'rating' => 5, 'body' => 'Merchant-managed media.'], [UploadedFile::fake()->image('merchant.jpg')], $this->user);
        $this->assertCount(1, $merchant->media);
    }

    public function test_read_only_member_can_view_form_tab_and_preview_but_cannot_save(): void
    {
        [$viewer, $organization, $store] = $this->context('viewer', false);
        $this->seed(PermissionSeeder::class);
        $role = Role::create(['organization_id' => $organization->id, 'name' => 'Form viewer', 'slug' => 'form-viewer', 'is_system' => false]);
        $role->permissions()->sync(Permission::whereIn('slug', ['apps.view', 'products.view'])->pluck('id'));
        $viewer->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => $store->id]);
        $base = "/organizations/{$organization->id}/stores/{$store->id}/deco-reviews";

        $this->actingAs($viewer)->get($base.'?tab=form')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('DecoReviews/Index', false)->where('tab', 'form')->where('canManage', false)->where('formConfig.version', 'initial'));
        $this->actingAs($viewer)->get($base.'/form-preview')->assertOk();
        $this->actingAs($viewer)->put($base.'/form', $this->configuration())->assertForbidden();
    }

    public function test_preview_is_non_submitting_escapes_labels_and_exposes_only_hidden_version_field(): void
    {
        $question = $this->question(['label' => '<script>alert("x")</script>']);
        $saved = $this->save($this->configuration([], [$question]));
        $base = "/organizations/{$this->organization->id}/stores/{$this->store->id}/deco-reviews";
        $response = $this->actingAs($this->user)->get($base.'/form-preview')->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('data-form-preview="true"', $html);
        $this->assertStringContainsString('type="hidden" name="form_version" value="'.$saved['version'].'"', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert("x")</script>', $html);
        $this->assertStringContainsString('action=""', $html);
        $this->assertDatabaseCount('deco_reviews', 0);
    }

    public function test_signed_invitation_renders_and_submits_versioned_custom_answers_and_rejects_stale_form(): void
    {
        $public = $this->question(['label' => 'Public fit', 'public' => true]);
        $private = $this->question(['id' => (string) Str::uuid(), 'label' => 'Private note', 'required' => false,
            'public' => false, 'type' => 'multiple', 'options' => ['Quiet', 'Stable']]);
        $saved = $this->save($this->configuration(['thank_you' => 'Custom thank you.'], [$public, $private]));
        $order = $this->order($this->product);
        $makeInvitation = fn (Order $inviteOrder) => Invitation::create([
            'uuid' => (string) Str::uuid(), 'organization_id' => $this->organization->id, 'store_id' => $this->store->id,
            'order_id' => $inviteOrder->id, 'product_id' => $this->product->id, 'email' => $inviteOrder->email,
            'email_hash' => app(ReviewService::class)->emailHash($this->store, $inviteOrder->email),
            'status' => 'scheduled', 'due_at' => now(), 'expires_at' => now()->addDays(30),
        ]);
        $invitation = $makeInvitation($order);
        $signedUrl = URL::temporarySignedRoute('deco-reviews.write', now()->addMinutes(15), ['invitation' => $invitation->uuid]);

        $html = $this->get($signedUrl)->assertOk()->getContent();
        $this->assertStringContainsString('Public fit', $html);
        $this->assertStringContainsString('Private note', $html);
        $this->assertStringContainsString('name="form_version" value="'.$saved['version'].'"', $html);

        $response = $this->postJson($signedUrl, ['consent' => true, 'form_version' => $saved['version'],
            'author_name' => 'Signed Buyer', 'rating' => 5, 'title' => 'Signed review', 'body' => 'Submitted from signed form.',
            'answers' => [$public['id'] => 'Perfect', $private['id'] => ['Quiet', 'Stable']],
        ])->assertCreated()->assertJsonPath('message', 'Custom thank you.');
        $review = Review::where('uuid', $response->json('data.uuid'))->firstOrFail();
        $this->assertSame(['Public fit', 'Private note'], array_column($review->form_answers, 'label'));
        $this->assertSame([true, false], array_column($review->form_answers, 'public'));

        $updated = $this->save($this->configuration(['version' => $saved['version'], 'thank_you' => 'New response.'], [$public, $private]));
        $this->assertNotSame($saved['version'], $updated['version']);
        $secondOrder = $order->replicate();
        $secondOrder->shopify_order_id = '77124';
        $secondOrder->order_number = '#77124';
        $secondOrder->save();
        OrderItem::create(['order_id' => $secondOrder->id, 'shopify_line_item_id' => '88124', 'product_id' => $this->product->id,
            'shopify_product_id' => $this->product->shopify_product_id, 'title' => $this->product->title,
            'quantity' => 1, 'current_quantity' => 1, 'price' => 90]);
        $secondInvitation = $makeInvitation($secondOrder);
        $staleUrl = URL::temporarySignedRoute('deco-reviews.write', now()->addMinutes(15), ['invitation' => $secondInvitation->uuid]);
        $this->postJson($staleUrl, ['consent' => true, 'form_version' => $saved['version'],
            'author_name' => 'Second Buyer', 'rating' => 4, 'body' => 'Must not be stored.',
            'answers' => [$public['id'] => 'Perfect'],
        ])->assertUnprocessable()->assertJsonValidationErrors('form_version');
        $this->assertDatabaseCount('deco_reviews', 1);
    }
}
