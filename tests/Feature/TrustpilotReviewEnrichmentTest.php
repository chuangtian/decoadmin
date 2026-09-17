<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\ReputationMention;
use App\Models\ReputationMentionProductMatch;
use App\Models\Store;
use App\Services\Reputation\ReputationDashboardService;
use App\Services\Reputation\TrustpilotReviewEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TrustpilotReviewEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uniquely_matches_reviewer_and_only_links_current_store_active_bike_models(): void
    {
        $organization = Organization::query()->create(['name' => 'Macfox', 'code' => 'macfox']);
        $store = $organization->stores()->create([
            'name' => 'Macfox Bike',
            'shopify_domain' => 'macfoxebike.myshopify.com',
            'status' => 'active',
            'timezone' => 'America/Los_Angeles',
        ]);
        $otherStore = $organization->stores()->create([
            'name' => 'Other Store',
            'shopify_domain' => 'other.myshopify.com',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);

        $x1s = $this->product($organization, $store, 101, 'Macfox X1S Commuter E-bike', 'macfox-x1');
        $collaboration = $this->product($organization, $store, 102, 'Macfox E-bike X1S x Bs.zay', 'x1s-x-bs-zay');
        $this->product($organization, $store, 103, 'Macfox M19 Electric Bike for Teenager', 'macfox-m19', 'draft');
        $this->electricBikes($organization, $store, [$x1s, $collaboration]);

        $content = 'Purchased a Macfox M19 electric bike for my child. We have two other Macfox bikes (X1S models) and wanted one to match ours.';
        $mention = $this->mention($organization, $store, 'example-review', $content);
        $collaborationMention = $this->mention(
            $organization,
            $store,
            'collaboration-review',
            'My X1S x Bs.zay is the best looking bike and rides smoothly.',
        );
        $otherMention = $this->mention($organization, $otherStore, 'other-review', $content);

        $snapshot = $this->snapshot([
            $this->review('aaaaaaaaaaaaaaaaaaaaaaaa', 'Jimmy Cazares', 'Purchased a Macfox M19 electric bike…', $content),
            $this->review('bbbbbbbbbbbbbbbbbbbbbbbb', 'Alex Rider', 'Great collaboration', (string) $collaborationMention->content),
        ]);

        $result = app(TrustpilotReviewEnrichmentService::class)->enrich($store, $snapshot);

        $this->assertSame(2, $result['reviewer_matches']);
        $this->assertSame('Jimmy Cazares', $mention->refresh()->reviewer_name);
        $this->assertSame('https://www.trustpilot.com/reviews/aaaaaaaaaaaaaaaaaaaaaaaa', $mention->url);
        $this->assertSame('Purchased a Macfox M19 electric bike…', $mention->title);
        $this->assertSame('M19 | X1S', $mention->model_name);
        $this->assertSame(['M19', 'X1S'], $mention->metrics['detected_models']);
        $this->assertNull($otherMention->refresh()->reviewer_name);

        $exampleMatches = ReputationMentionProductMatch::query()->where('reputation_mention_id', $mention->id)->get();
        $this->assertCount(1, $exampleMatches);
        $this->assertSame($x1s->id, $exampleMatches->sole()->product_id);
        $this->assertSame('mentioned', $exampleMatches->sole()->match_role);

        $collaborationMatches = ReputationMentionProductMatch::query()->where('reputation_mention_id', $collaborationMention->id)->get();
        $this->assertCount(1, $collaborationMatches);
        $this->assertSame($collaboration->id, $collaborationMatches->sole()->product_id);
        $this->assertSame('primary', $collaborationMatches->sole()->match_role);
        $this->assertSame('X1S x Bs.zay', $collaborationMention->refresh()->model_name);

        $dashboard = app(ReputationDashboardService::class)->overview($store, [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'tab' => 'reviews',
        ]);
        $record = collect($dashboard['records']->items())->firstWhere('uuid', $mention->uuid);
        $this->assertSame('Jimmy Cazares', $record['reviewer_name']);
        $this->assertSame([[
            'title' => 'Macfox X1S Commuter E-bike',
            'handle' => 'macfox-x1',
            'role' => 'mentioned',
        ]], $record['matched_products']);
    }

    public function test_model_capture_preserves_case_longest_suffix_locale_and_collaboration_text(): void
    {
        $organization = Organization::query()->create(['name' => 'Macfox', 'code' => 'macfox-model-integrity']);
        $store = $organization->stores()->create([
            'name' => 'Macfox Bike', 'shopify_domain' => 'macfoxebike.myshopify.com',
            'status' => 'active', 'timezone' => 'UTC',
        ]);
        $x7 = $this->product($organization, $store, 301, 'Macfox X7', 'macfox-x7');
        $x7l = $this->product($organization, $store, 302, 'Macfox X7L', 'macfox-x7l');
        $x1s = $this->product($organization, $store, 303, 'Macfox X1s', 'macfox-x1s');
        $collaboration = $this->product($organization, $store, 304, 'Macfox X1s x Bs.zay', 'x1s-x-bs-zay');
        $this->electricBikes($organization, $store, [$x7, $x7l, $x1s, $collaboration]);

        $cases = [
            'x7l' => 'X7L',
            'x7l-eu' => 'X7L欧版',
            'mixedcase' => 'X1s',
            'lowercase' => 'x1s',
            'collaboration' => '1*X1sxBs.zay',
        ];
        $reviews = [];
        foreach ($cases as $key => $content) {
            $this->mention($organization, $store, $key, "Riding {$content} every day.");
            $reviews[] = $this->review(substr(hash('sha256', $key), 0, 24), "Reviewer {$key}", $content, "Riding {$content} every day.");
        }

        app(TrustpilotReviewEnrichmentService::class)->enrich($store, $this->snapshot($reviews));

        foreach ($cases as $key => $expected) {
            $mention = ReputationMention::query()->where('canonical_key', hash('sha256', $key))->sole();
            $this->assertSame($expected, $mention->model_name);
            $this->assertSame([$expected], $mention->metrics['detected_models']);
        }

        $x7lMention = ReputationMention::query()->where('canonical_key', hash('sha256', 'x7l'))->sole();
        $this->assertSame($x7l->id, $x7lMention->productMatches()->sole()->product_id);
        $this->assertNotSame($x7->id, $x7lMention->productMatches()->sole()->product_id);

        $euMention = ReputationMention::query()->where('canonical_key', hash('sha256', 'x7l-eu'))->sole();
        $this->assertSame($x7l->id, $euMention->productMatches()->sole()->product_id);

        $mixedcaseMention = ReputationMention::query()->where('canonical_key', hash('sha256', 'mixedcase'))->sole();
        $this->assertSame($x1s->id, $mixedcaseMention->productMatches()->sole()->product_id);

        $lowercaseMention = ReputationMention::query()->where('canonical_key', hash('sha256', 'lowercase'))->sole();
        $this->assertCount(0, $lowercaseMention->productMatches);

        $collaborationMention = ReputationMention::query()->where('canonical_key', hash('sha256', 'collaboration'))->sole();
        $this->assertSame($collaboration->id, $collaborationMention->productMatches()->sole()->product_id);
        $this->assertSame('X1sxBs.zay', $collaborationMention->productMatches()->sole()->matched_alias);
    }

    public function test_ambiguous_content_matches_do_not_write_a_reviewer_name_and_dry_run_rolls_back(): void
    {
        $organization = Organization::query()->create(['name' => 'Macfox', 'code' => 'macfox-ambiguous']);
        $store = $organization->stores()->create([
            'name' => 'Macfox Bike',
            'shopify_domain' => 'macfoxebike.myshopify.com',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);
        $x7 = $this->product($organization, $store, 201, 'Macfox X7', 'macfox-x7');
        $this->electricBikes($organization, $store, [$x7]);
        $mention = $this->mention($organization, $store, 'ambiguous', 'My Macfox X7 is great.');

        $snapshot = $this->snapshot([
            $this->review('cccccccccccccccccccccccc', 'First Reviewer', 'Great bike', (string) $mention->content),
            $this->review('dddddddddddddddddddddddd', 'Second Reviewer', 'Same review', (string) $mention->content),
        ]);

        $result = app(TrustpilotReviewEnrichmentService::class)->enrich($store, $snapshot, true);

        $this->assertSame(0, $result['reviewer_matches']);
        $this->assertNull($mention->refresh()->reviewer_name);
        $this->assertDatabaseMissing('reputation_mention_product_matches', [
            'reputation_mention_id' => $mention->id,
        ]);
    }

    /** @param list<Product> $products */
    private function electricBikes(Organization $organization, Store $store, array $products): void
    {
        $collection = ProductCollection::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_collection_id' => 9001,
            'title' => 'Electric Bikes',
            'handle' => 'electric-bike',
            'sync_batch' => (string) Str::uuid(),
            'synced_at' => now(),
        ]);

        foreach ($products as $product) {
            $collection->products()->attach($product->id, [
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'shopify_product_id' => $product->shopify_product_id,
                'sync_batch' => (string) Str::uuid(),
            ]);
        }
    }

    private function product(
        Organization $organization,
        Store $store,
        int $shopifyId,
        string $title,
        string $handle,
        string $status = 'active',
    ): Product {
        return Product::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_product_id' => $shopifyId,
            'title' => $title,
            'handle' => $handle,
            'status' => $status,
            'online_store_url' => $status === 'active' ? "https://macfoxbike.com/products/{$handle}" : null,
            'synced_at' => now(),
        ]);
    }

    private function mention(Organization $organization, Store $store, string $key, string $content): ReputationMention
    {
        return ReputationMention::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'source' => 'trustpilot',
            'canonical_key' => hash('sha256', $key),
            'content' => $content,
            'rating' => 5,
            'published_at' => '2026-07-31 12:00:00',
            'metrics' => [],
            'is_negative' => false,
            'is_active' => true,
            'synced_at' => now(),
        ]);
    }

    /** @param list<array<string, mixed>> $reviews @return array<string, mixed> */
    private function snapshot(array $reviews): array
    {
        return [
            'schema' => 'trustpilot-public-review-snapshot-v1',
            'business_domain' => 'macfoxbike.com',
            'reviews' => $reviews,
        ];
    }

    /** @return array<string, mixed> */
    private function review(string $id, string $name, string $title, string $content): array
    {
        return [
            'id' => $id,
            'reviewer_name' => $name,
            'title' => $title,
            'content' => $content,
            'rating' => 5,
            'url' => "https://www.trustpilot.com/reviews/{$id}",
        ];
    }
}
