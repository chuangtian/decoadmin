<?php

namespace Tests\DecoReviews;

use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use DecoReviews\Models\Review;
use DecoReviews\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class ImportProviderTest extends TestCase
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
        $organization = Organization::create(['name' => "Imports {$suffix}", 'code' => 'imports-'.Str::lower(Str::random(8)), 'status' => 'active']);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create(['name' => "Store {$suffix}", 'shopify_domain' => 'imports-'.Str::lower(Str::random(8)).'.myshopify.com', 'status' => 'active']);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $product = Product::create(['organization_id' => $organization->id, 'store_id' => $store->id, 'shopify_product_id' => (string) random_int(100000, 999999),
            'title' => 'Road Bike', 'handle' => 'road-bike', 'status' => 'active', 'synced_at' => now()]);

        return [$user, $organization, $store, $product];
    }

    private function csv(string $name, array $rows): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, implode("\n", $rows));
    }

    public function test_supported_provider_mappings_preserve_batch_attribution_without_fetching_media(): void
    {
        [$user, , $store, $product] = $this->context('providers');
        $fixtures = [
            'loox' => ['product_handle,rating,author,body,created_at,email,title,photo_url,verified_purchase', 'road-bike,5,Loox Buyer,Loox body,2026-09-01,loox@example.test,Loox title,https://cdn.example.test/photo.jpg,true'],
            'judge_me' => ['product_handle,rating,reviewer_name,body,review_date,reviewer_email,title', 'road-bike,4,Judge Buyer,Judge body,2026-09-02,judge@example.test,Judge title'],
            'yotpo' => ['Product ID,Review Score,Reviewer Display Name,Review Content,Review Creation Date,Reviewer Email,Review Title', "{$product->shopify_product_id},3,Yotpo Buyer,Yotpo body,2026-09-03,yotpo@example.test,Yotpo title"],
            'okendo' => ['productId,rating,name,body,dateCreated,email,title,imageUrls', "{$product->shopify_product_id},2,Okendo Buyer,Okendo body,2026-09-04,okendo@example.test,Okendo title,https://cdn.example.test/photo.jpg"],
            'shopify_product_reviews' => ['product_handle,state,rating,title,author,email,location,body,reply,created_at,replied_at', 'road-bike,published,1,SPR title,SPR Buyer,spr@example.test,Sydney,SPR body,,2026-09-05,'],
        ];
        foreach ($fixtures as $provider => $rows) {
            $batch = app(ImportService::class)->import($store, $user, $this->csv("{$provider}.csv", $rows), $provider);
            $review = Review::where('import_id', $batch->id)->firstOrFail();
            $this->assertSame($provider, $batch->provider);
            $this->assertSame('import', $review->source);
            $this->assertSame($store->id, $review->store_id);
        }
        $this->assertDatabaseCount('deco_review_media', 0);
    }

    public function test_auto_detection_accepts_one_signature_and_rejects_ambiguous_or_unknown_headers(): void
    {
        [$user, , $store] = $this->context('detect');
        $judge = app(ImportService::class)->import($store, $user, $this->csv('judge.csv', [
            'product_handle,rating,reviewer_name,body,review_date', 'road-bike,5,Buyer,Clear source,2026-09-01',
        ]), 'auto');
        $this->assertSame('judge_me', $judge->provider);

        foreach ([
            ['product_handle,rating,author_name,body,reviewed_at,author,created_at,state,location', 'road-bike,5,A,Body,2026-09-01,B,2026-09-01,published,Sydney'],
            ['score,name,comment,date', '5,A,Body,2026-09-01'],
        ] as $index => $rows) {
            try {
                app(ImportService::class)->import($store, $user, $this->csv("invalid-{$index}.csv", $rows), 'auto');
                $this->fail('Ambiguous or unknown CSV was accepted.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('provider', $error->errors());
            }
        }
    }

    public function test_import_authorization_and_product_resolution_cannot_cross_tenants(): void
    {
        [$user, , $store] = $this->context('local');
        [, , $foreignStore, $foreignProduct] = $this->context('foreign');
        $foreignProduct->update(['handle' => 'foreign-only']);
        $outsider = User::factory()->create(['email_verified_at' => now(), 'metadata' => []]);
        $file = fn () => $this->csv('foreign.csv', ['product_handle,rating,author_name,body,reviewed_at', "{$foreignProduct->handle},5,A,Cross tenant,2026-09-01"]);
        try {
            app(ImportService::class)->import($foreignStore, $outsider, $file());
            $this->fail('Unauthorized foreign store import was accepted.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $batch = app(ImportService::class)->import($store, $user, $file());
        $this->assertSame(0, $batch->imported);
        $this->assertSame('PRODUCT_NOT_FOUND', $batch->errors[0]['code']);
        $this->assertDatabaseCount('deco_reviews', 0);
    }

    public function test_handle_ending_in_digits_is_never_resolved_as_a_shopify_product_id(): void
    {
        [$user, , $store, $product] = $this->context('numeric-handle');
        $product->update(['handle' => 'actual-handle', 'shopify_product_id' => '123']);

        $batch = app(ImportService::class)->import($store, $user, $this->csv('custom.csv', [
            'product_handle,rating,author_name,body,reviewed_at',
            'missing-123,5,Buyer,Must not resolve by trailing digits,2026-09-01',
        ]), 'custom');

        $this->assertSame(0, $batch->imported);
        $this->assertSame('PRODUCT_NOT_FOUND', $batch->errors[0]['code']);
        $this->assertDatabaseCount('deco_reviews', 0);
    }

    public function test_imported_formula_values_remain_safe_in_csv_export_and_custom_format_regresses_cleanly(): void
    {
        [$user, $organization, $store] = $this->context('formula');
        $batch = app(ImportService::class)->import($store, $user, $this->csv('custom.csv', [
            'product_handle,rating,author_name,body,reviewed_at,title',
            'road-bike,5,"=HYPERLINK(""https://invalid.test"")",@dangerous,2026-09-01,"+SUM(1,1)"',
        ]), 'custom');
        $this->assertSame(1, $batch->imported);
        $csv = $this->actingAs($user)->get("/organizations/{$organization->id}/stores/{$store->id}/deco-reviews/export")->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'+SUM", $csv);
        $this->assertStringContainsString("'@dangerous", $csv);
    }

    public function test_management_import_requires_an_explicit_provider_parameter(): void
    {
        [$user, $organization, $store] = $this->context('request');
        $url = "/organizations/{$organization->id}/stores/{$store->id}/deco-reviews/imports";
        $rows = ['product_handle,rating,author_name,body,reviewed_at', 'road-bike,5,Buyer,Request import,2026-09-01'];
        $this->actingAs($user)->post($url, ['file' => $this->csv('missing-provider.csv', $rows)])->assertSessionHasErrors('provider');
        $this->actingAs($user)->post($url, ['provider' => 'custom', 'file' => $this->csv('custom.csv', $rows)])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('deco_review_imports', ['store_id' => $store->id, 'provider' => 'custom', 'imported' => 1]);
    }

    public function test_scoped_failure_report_contains_only_line_and_stable_error_code(): void
    {
        [$user, $organization, $store] = $this->context('report');
        $batch = app(ImportService::class)->import($store, $user, $this->csv('report.csv', [
            'product_handle,rating,author_name,body,reviewed_at',
            'missing-product,5,Private Buyer,Private body,2026-09-01',
        ]), 'custom');
        $url = "/organizations/{$organization->id}/stores/{$store->id}/deco-reviews/imports/{$batch->uuid}/errors";
        $csv = $this->actingAs($user)->get($url)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->streamedContent();
        $this->assertStringContainsString("line,error_code\n2,PRODUCT_NOT_FOUND", $csv);
        $this->assertStringNotContainsString('Private Buyer', $csv);
        $this->assertStringNotContainsString('Private body', $csv);

        [, $foreignOrganization, $foreignStore] = $this->context('report-foreign');
        $this->actingAs($user)->get("/organizations/{$foreignOrganization->id}/stores/{$foreignStore->id}/deco-reviews/imports/{$batch->uuid}/errors")
            ->assertNotFound();
    }
}
