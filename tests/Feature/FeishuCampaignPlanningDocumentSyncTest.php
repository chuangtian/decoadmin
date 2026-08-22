<?php

namespace Tests\Feature;

use App\Models\CampaignActivity;
use App\Models\CampaignPlanningAsset;
use App\Models\CampaignPlanningDocument;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\Feishu\CampaignPlanningDocumentSyncService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FeishuCampaignPlanningDocumentSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_a_safe_local_document_snapshot_and_protected_assets(): void
    {
        Storage::fake('local');
        $this->configureFeishu();
        [$organization, $store, $activity] = $this->activityContext(
            'https://example.feishu.cn/docx/doc_token_one',
        );
        $this->fakeDocumentApi('doc_token_one', withAssets: true);

        $result = app(CampaignPlanningDocumentSyncService::class)->syncStore($store);

        $this->assertSame(1, $result['documents']);
        $this->assertSame(1, $result['inserted']);
        $this->assertSame(0, $result['failed']);
        $document = CampaignPlanningDocument::query()->sole();
        $this->assertSame($organization->id, $document->organization_id);
        $this->assertSame($store->id, $document->store_id);
        $this->assertSame($activity->id, $document->campaign_activity_id);
        $this->assertSame('docx', $document->source_type);
        $this->assertSame('doc_token_one', $document->source_document_token);
        $this->assertSame('总统日活动策划', $document->title);
        $this->assertSame('synced', $document->sync_status);
        $this->assertCount(5, $document->content_blocks);
        $this->assertStringContainsString('<h2>活动主题</h2>', $document->rendered_html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $document->rendered_html);
        $this->assertStringNotContainsString('<script>', $document->rendered_html);
        $this->assertStringContainsString('总统日活动策划', $document->plain_text);

        $assets = CampaignPlanningAsset::query()->orderBy('asset_type')->get();
        $this->assertCount(2, $assets);
        $this->assertTrue($assets->every(fn (CampaignPlanningAsset $asset): bool => $asset->local_disk === 'local'));
        $this->assertTrue($assets->every(fn (CampaignPlanningAsset $asset): bool => str_starts_with(
            $asset->local_url,
            '/campaign-planning-documents/'.$document->id.'/assets/',
        )));

        foreach ($assets as $asset) {
            Storage::disk('local')->assertExists($asset->local_path);
        }

        $image = $assets->firstWhere('asset_type', 'image');
        $this->assertNotNull($image);
        $this->assertSame('image/webp', $image->mime_type);
        $this->assertSame(1, $image->width);
        $this->assertSame(1, $image->height);
        $this->assertStringStartsWith('RIFF', Storage::disk('local')->get($image->local_path));

        [$user, $otherStore] = $this->viewer($organization, $store);
        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get($image->local_url)
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=3600, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $otherStore))
            ->get($image->local_url)
            ->assertNotFound();
    }

    public function test_it_resolves_wiki_links_reuses_unchanged_snapshots_and_is_not_scheduled(): void
    {
        Storage::fake('local');
        $this->configureFeishu();
        [, $store] = $this->activityContext('https://example.feishu.cn/wiki/wiki_node_one');
        $this->fakeDocumentApi('resolved_doc_token', withAssets: false, wikiNode: 'wiki_node_one');

        $first = app(CampaignPlanningDocumentSyncService::class)->syncStore($store);
        $second = app(CampaignPlanningDocumentSyncService::class)->syncStore($store);

        $this->assertSame(1, $first['inserted']);
        $this->assertSame(1, $second['unchanged']);
        $this->assertDatabaseCount('campaign_planning_documents', 1);
        $document = CampaignPlanningDocument::query()->sole();
        $this->assertSame('wiki', $document->source_type);
        $this->assertSame('wiki_node_one', $document->source_node_token);
        $this->assertSame('resolved_doc_token', $document->source_document_token);
        $this->assertDatabaseCount('campaign_planning_assets', 0);

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains(
                (string) $event->command,
                'feishu:sync-campaign-planning-documents',
            ));

        $this->assertNull($event, '策划书同步必须仅允许手动执行，不能加入定时计划。');
    }

    public function test_a_failed_refresh_keeps_the_last_successful_snapshot(): void
    {
        Storage::fake('local');
        $this->configureFeishu();
        [, $store, $activity] = $this->activityContext('https://example.feishu.cn/docx/doc_token_one');
        $this->fakeDocumentApi('doc_token_one', withAssets: false);
        app(CampaignPlanningDocumentSyncService::class)->syncStore($store);
        $original = CampaignPlanningDocument::query()->sole();
        $originalHtml = $original->rendered_html;
        $originalHash = $original->content_hash;

        $activity->forceFill([
            'planning_document' => 'https://untrusted.example.com/docx/secret_doc_token',
        ])->save();
        $result = app(CampaignPlanningDocumentSyncService::class)->syncStore($store);

        $this->assertSame(1, $result['failed']);
        $document = CampaignPlanningDocument::query()->sole();
        $this->assertSame('failed', $document->sync_status);
        $this->assertSame($originalHtml, $document->rendered_html);
        $this->assertSame($originalHash, $document->content_hash);
        $this->assertStringNotContainsString('secret doc token', (string) $document->last_error);
    }

    private function configureFeishu(): void
    {
        Config::set('services.feishu_table.app_id', 'cli_test');
        Config::set('services.feishu_table.app_secret', 'test-secret');
    }

    private function fakeDocumentApi(string $documentToken, bool $withAssets, ?string $wikiNode = null): void
    {
        Http::fake(function (Request $request) use ($documentToken, $withAssets, $wikiNode) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/auth/v3/tenant_access_token/internal')) {
                return Http::response(['code' => 0, 'tenant_access_token' => 'tenant-token']);
            }

            if (str_ends_with($path, '/wiki/v2/spaces/get_node')) {
                $this->assertSame($wikiNode, $request->data()['token'] ?? null);

                return Http::response(['code' => 0, 'data' => ['node' => [
                    'obj_type' => 'docx',
                    'obj_token' => $documentToken,
                ]]]);
            }

            if (str_ends_with($path, "/docx/v1/documents/{$documentToken}/blocks")) {
                return Http::response([
                    'code' => 0,
                    'data' => [
                        'has_more' => false,
                        'items' => $this->documentBlocks($withAssets),
                    ],
                ]);
            }

            if (str_ends_with($path, "/docx/v1/documents/{$documentToken}")) {
                return Http::response(['code' => 0, 'data' => ['document' => [
                    'title' => '总统日活动策划',
                    'revision_id' => 7,
                ]]]);
            }

            if (preg_match('#/drive/v1/medias/(image_token|file_token)/download$#', $path, $matches)) {
                if ($matches[1] === 'image_token') {
                    return Http::response(
                        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
                        200,
                        ['Content-Type' => 'image/png'],
                    );
                }

                return Http::response('%PDF-1.4 test', 200, ['Content-Type' => 'application/pdf']);
            }

            return Http::response(['code' => 404], 404);
        });
    }

    /** @return list<array<string, mixed>> */
    private function documentBlocks(bool $withAssets): array
    {
        $children = ['heading', 'paragraph'];

        if ($withAssets) {
            $children = [...$children, 'image', 'file'];
        }

        $blocks = [
            ['block_id' => 'root', 'block_type' => 1, 'children' => $children],
            [
                'block_id' => 'heading',
                'parent_id' => 'root',
                'block_type' => 4,
                'heading2' => ['elements' => [['text_run' => ['content' => '活动主题']]]],
            ],
            [
                'block_id' => 'paragraph',
                'parent_id' => 'root',
                'block_type' => 2,
                'text' => ['elements' => [[
                    'text_run' => [
                        'content' => '<script>alert(1)</script>',
                        'text_element_style' => ['bold' => true],
                    ],
                ]]],
            ],
        ];

        if ($withAssets) {
            $blocks[] = [
                'block_id' => 'image',
                'parent_id' => 'root',
                'block_type' => 27,
                'image' => ['token' => 'image_token'],
            ];
            $blocks[] = [
                'block_id' => 'file',
                'parent_id' => 'root',
                'block_type' => 23,
                'file' => ['token' => 'file_token', 'name' => '完整策划书.pdf'],
            ];
        }

        return $blocks;
    }

    /** @return array{Organization, Store, CampaignActivity} */
    private function activityContext(string $planningDocument): array
    {
        $organization = Organization::query()->create([
            'name' => 'Campaign Planning Organization',
            'code' => 'campaign-planning-'.strtolower(fake()->unique()->lexify('????????')),
        ]);
        $store = $organization->stores()->create([
            'name' => 'Campaign Planning Store',
            'shopify_domain' => strtolower(fake()->unique()->lexify('????????')).'.myshopify.com',
            'status' => 'active',
            'timezone' => 'America/Los_Angeles',
            'currency' => 'USD',
        ]);
        $activity = $store->campaignActivities()->create([
            'organization_id' => $organization->id,
            'source_record_id' => 'record-'.strtolower(fake()->unique()->lexify('????????')),
            'campaign_id' => 'MACFOX-2026-010',
            'planning_document' => $planningDocument,
            'synced_at' => now(),
        ]);

        return [$organization, $store, $activity];
    }

    /** @return array{User, Store} */
    private function viewer(Organization $organization, Store $store): array
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $otherStore = $organization->stores()->create([
            'name' => 'Other Store',
            'shopify_domain' => strtolower(fake()->unique()->lexify('????????')).'.myshopify.com',
            'status' => 'active',
        ]);
        $otherStore->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', 'viewer')->firstOrFail();
        $user->roles()->attach($role, [
            'organization_id' => $organization->id,
            'store_id' => null,
        ]);

        return [$user, $otherStore];
    }

    /** @return array{current_organization_id: int, current_store_id: int} */
    private function contextSession(Organization $organization, Store $store): array
    {
        return ['current_organization_id' => $organization->id, 'current_store_id' => $store->id];
    }
}
