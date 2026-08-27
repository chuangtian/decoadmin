<?php

namespace Tests\Feature;

use App\Jobs\SyncReputationForStore;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\ReputationGoal;
use App\Models\ReputationMention;
use App\Models\ReputationResourceRequest;
use App\Models\ReputationRisk;
use App\Models\ReputationSyncRun;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Models\User;
use App\Services\Feishu\FeishuBitableClient;
use App\Services\Reputation\ReputationDashboardService;
use App\Services\Reputation\ReputationSyncService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class ReputationMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_calculates_current_project_database_records_and_isolates_store(): void
    {
        [$user, $organization, $store] = $this->context('operator');
        $otherStore = $organization->stores()->create([
            'name' => 'Other Reputation Store',
            'shopify_domain' => 'other-reputation.myshopify.com',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);

        $this->mention($organization, $store, 'trustpilot', 'review-five', ['rating' => 5, 'content' => 'Excellent bike']);
        $this->mention($organization, $store, 'website', 'review-one', ['rating' => 1, 'content' => 'Brake issue', 'model_name' => 'X1', 'is_negative' => true]);
        $this->mention($organization, $store, 'reddit', 'reddit-one', ['content' => 'Launch discussion', 'metrics' => ['views' => 1200, 'upvotes' => 40, 'comments' => 12, 'spend' => 50]]);
        $this->mention($organization, $store, 'threads', 'threads-one', ['content' => 'Threads launch', 'metrics' => ['likes' => 20, 'replies' => 4, 'reposts' => 3, 'shares' => 2]]);
        $this->mention($organization, $store, 'trustpilot', 'previous-review', ['rating' => 1, 'content' => 'Previous period review', 'published_at' => '2026-07-20 12:00:00', 'is_negative' => true]);
        $this->mention($organization, $otherStore, 'trustpilot', 'other-review', ['rating' => 1, 'content' => 'Must not leak', 'is_negative' => true]);
        ReputationGoal::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'month' => '2026-08-01',
            'metric' => 'satisfied_reviews',
            'target_value' => 2,
        ]);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('reputation.overview', ['date_from' => '2026-08-01', 'date_to' => '2026-08-31', 'comparison' => 'previous']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reputation/Overview')
                ->where('dashboard.schema', 'reputation-overview-v1')
                ->where('dashboard.summary.total', 4)
                ->where('dashboard.summary.reviews', 2)
                ->where('dashboard.summary.average_rating', 3)
                ->where('dashboard.summary.satisfied_reviews', 1)
                ->where('dashboard.summary.low_rating_reviews', 1)
                ->where('dashboard.summary.positive_rate', 50)
                ->where('dashboard.summary.negative_rate', 50)
                ->where('dashboard.summary.reddit.views', 1200)
                ->where('dashboard.summary.reddit.comments', 12)
                ->where('dashboard.summary.threads.engagement', 29)
                ->where('dashboard.goals.0.metric', 'satisfied_reviews')
                ->where('dashboard.goals.0.completion_percent', 50)
                ->where('dashboard.records.total', 2)
                ->where('dashboard.comparison.date_from', '2026-07-01')
                ->where('dashboard.comparison.date_to', '2026-07-31')
                ->where('dashboard.comparison.summary.reviews', 1)
                ->where('dashboard.comparison.metrics.reviews.change_percent', 100)
                ->where('dashboard.trends.19.star_5', 1)
                ->missing('dashboard.ai'));

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('reputation.risks'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reputation/RiskSync')
                ->where('dashboard.schema', 'reputation-risk-sync-v1')
                ->missing('dashboard.ai'));
    }

    public function test_reviews_records_support_all_sources_and_platform_filters_without_cross_store_or_sensitive_data(): void
    {
        [$user, $organization, $store] = $this->context('operator', 'record-sources');
        $otherStore = $organization->stores()->create([
            'name' => 'Other Reputation Source Store',
            'shopify_domain' => 'other-reputation-source.myshopify.com',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);

        $this->mention($organization, $store, 'trustpilot', 'current-review', ['content' => 'Current review']);
        $this->mention($organization, $store, 'reddit', 'current-reddit', [
            'content' => 'Current Reddit record',
            'metrics' => ['views' => 100, 'comments' => 4, 'upvotes' => 10],
            'source_payloads_encrypted' => ['source' => ['private_note' => 'must-not-leak']],
        ]);
        $this->mention($organization, $store, 'threads', 'current-threads', [
            'content' => 'Current Threads record',
            'metrics' => ['likes' => 10, 'replies' => 2, 'reposts' => 1, 'shares' => 1],
        ]);
        $this->mention($organization, $otherStore, 'reddit', 'other-store-reddit', ['content' => 'Must not leak']);

        $filters = ['date_from' => '2026-08-01', 'date_to' => '2026-08-31', 'tab' => 'reviews'];
        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('reputation.overview', $filters))
            ->assertOk()
            ->assertDontSee('must-not-leak')
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.schema', 'reputation-overview-v1')
                ->where('dashboard.records.total', 3)
                ->has('dashboard.records.data', 3)
                ->missing('dashboard.analysis'));

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('reputation.overview', [...$filters, 'source' => 'reddit']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.filters.tab', 'reviews')
                ->where('dashboard.filters.source', 'reddit')
                ->where('dashboard.records.total', 1)
                ->has('dashboard.records.data', 1)
                ->where('dashboard.records.data.0.source', 'reddit')
                ->missing('dashboard.records.data.0.source_payloads')
                ->missing('dashboard.records.data.0.source_payloads_encrypted'));

        foreach (['reddit', 'threads'] as $tab) {
            $this->actingAs($user)
                ->withSession($this->contextSession($organization, $store))
                ->get(route('reputation.overview', [...$filters, 'tab' => $tab, 'source' => null]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('dashboard.records.total', 1)
                    ->where('dashboard.records.data.0.source', $tab));
        }
    }

    public function test_reddit_topics_use_only_current_store_period_content_and_return_stable_weekly_aggregation(): void
    {
        [$user, $organization, $store] = $this->context('operator', 'reddit-topics');
        $otherStore = $organization->stores()->create([
            'name' => 'Other Reddit Topic Store',
            'shopify_domain' => 'other-reddit-topic.myshopify.com',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);
        $otherOrganization = Organization::query()->create([
            'name' => 'Other Reddit Topic Organization',
            'code' => 'other-reddit-topic-organization',
        ]);
        $otherOrganizationStore = $otherOrganization->stores()->create([
            'name' => 'Other Organization Reddit Store',
            'shopify_domain' => 'other-organization-reddit.myshopify.com',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);

        $this->mention($organization, $store, 'reddit', 'purchase-one', [
            'title' => 'Should I buy X1 or X2?',
            'content' => 'Looking for a comparison before purchase.',
            'metrics' => ['views' => 100, 'comments' => 4, 'upvotes' => 10],
            'published_at' => '2026-08-04 12:00:00',
        ]);
        $this->mention($organization, $store, 'reddit', 'purchase-two', [
            'title' => 'Which bike is worth buying?',
            'metrics' => ['views' => 300, 'comments' => 6, 'upvotes' => 30],
            'published_at' => '2026-08-05 12:00:00',
        ]);
        $this->mention($organization, $store, 'reddit', 'technical', [
            'title' => 'Battery motor fault, please help',
            'metrics' => ['views' => 90, 'comments' => 5, 'upvotes' => 5],
            'source_payloads_encrypted' => [[
                '帖子类型' => '购买建议 / 对比',
                '目标关键词' => 'must-never-drive-topic',
            ]],
            'published_at' => '2026-08-06 12:00:00',
        ]);
        $this->mention($organization, $store, 'reddit', 'complaint', [
            'content' => 'Terrible customer service complaint and refund experience.',
            'metrics' => ['views' => 50, 'comments' => 8, 'upvotes' => 2],
            'published_at' => '2026-08-12 12:00:00',
        ]);
        $this->mention($organization, $store, 'reddit', 'community', [
            'content' => 'Community question: anyone have tips?',
            'metrics' => ['views' => 40, 'comments' => 3, 'upvotes' => 6],
            'published_at' => '2026-08-13 12:00:00',
        ]);
        $this->mention($organization, $store, 'reddit', 'lifestyle', [
            'content' => 'Weekend trail ride photo showcase.',
            'metrics' => ['views' => 70, 'comments' => 1, 'upvotes' => 12],
            'published_at' => '2026-08-19 12:00:00',
        ]);
        $this->mention($organization, $store, 'reddit', 'other', [
            'content' => 'Quarterly update.',
            'metrics' => ['views' => 10, 'comments' => 0, 'upvotes' => 0],
            'published_at' => '2026-08-20 12:00:00',
        ]);
        $this->mention($organization, $store, 'threads', 'threads-excluded', [
            'content' => 'Should I buy this bike?',
            'published_at' => '2026-08-04 12:00:00',
        ]);
        $this->mention($organization, $store, 'reddit', 'previous-period', [
            'content' => 'Should I buy this older bike?',
            'published_at' => '2026-07-31 12:00:00',
        ]);
        $this->mention($organization, $store, 'reddit', 'inactive', [
            'content' => 'Should I buy this inactive bike?',
            'is_active' => false,
            'published_at' => '2026-08-04 12:00:00',
        ]);
        $this->mention($organization, $otherStore, 'reddit', 'other-store', [
            'content' => 'Should I buy the other store bike?',
            'metrics' => ['views' => 99999, 'comments' => 999, 'upvotes' => 999],
            'published_at' => '2026-08-04 12:00:00',
        ]);
        $this->mention($otherOrganization, $otherOrganizationStore, 'reddit', 'other-organization', [
            'content' => 'Terrible complaint from another organization.',
            'published_at' => '2026-08-12 12:00:00',
        ]);

        $filters = ['date_from' => '2026-08-01', 'date_to' => '2026-08-31', 'tab' => 'reddit'];
        $dashboard = app(ReputationDashboardService::class)->overview($store, $filters);
        $averages = collect($dashboard['reddit_topics']['topic_averages'])->keyBy('topic');

        $this->assertSame('keyword-rules-v1', $dashboard['reddit_topics']['method']);
        $this->assertSame(['title', 'content'], $dashboard['reddit_topics']['source_fields']);
        $this->assertSame([
            '购买建议 / 对比',
            '产品技术 / 故障',
            '品牌声音 / 抱怨',
            '社区互动 / 问答',
            '骑行生活 / 展示',
            '其他',
        ], $averages->keys()->all());
        $this->assertSame([
            'topic' => '购买建议 / 对比',
            'views' => 200.0,
            'comments' => 5.0,
            'upvotes' => 20.0,
            'posts' => 2,
        ], $averages->get('购买建议 / 对比'));
        $this->assertSame(1, $averages->get('产品技术 / 故障')['posts']);
        $this->assertSame(90.0, $averages->get('产品技术 / 故障')['views']);
        $this->assertSame(1, $averages->get('品牌声音 / 抱怨')['posts']);
        $this->assertSame(1, $averages->get('社区互动 / 问答')['posts']);
        $this->assertSame(1, $averages->get('骑行生活 / 展示')['posts']);
        $this->assertSame(1, $averages->get('其他')['posts']);

        $week = collect($dashboard['reddit_topics']['weekly_trends'])->firstWhere('week', '2026-08-03');
        $distribution = collect($week['topic_distribution'])->keyBy('topic');
        $this->assertSame(3, $week['posts']);
        $this->assertSame(2, $distribution->get('购买建议 / 对比')['count']);
        $this->assertSame(66.67, $distribution->get('购买建议 / 对比')['percent']);
        $this->assertSame(1, $distribution->get('产品技术 / 故障')['count']);
        $this->assertSame(33.33, $distribution->get('产品技术 / 故障')['percent']);

        $serialized = json_encode($dashboard['reddit_topics'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('帖子类型', $serialized);
        $this->assertStringNotContainsString('目标关键词', $serialized);
        $this->assertStringNotContainsString('must-never-drive-topic', $serialized);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('reputation.overview', $filters))
            ->assertOk()
            ->assertDontSee('must-never-drive-topic')
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.reddit_topics.method', 'keyword-rules-v1')
                ->has('dashboard.reddit_topics.topic_averages', 6)
                ->where('dashboard.reddit_topics.topic_averages.0.posts', 2)
                ->where('dashboard.summary.reddit.posts', 7));
    }

    public function test_permissions_workflows_audit_and_cross_store_binding_are_enforced(): void
    {
        [$admin, $organization, $store] = $this->context('organization-admin');
        $session = $this->contextSession($organization, $store);

        $this->actingAs($admin)->withSession($session)->post(route('reputation.risks.store'), [
            'description' => 'Trustpilot low-rating report needs follow-up.',
            'severity' => 'high',
            'source' => 'trustpilot',
            'recommended_action' => 'Contact customer service and document the response.',
            'occurred_at' => '2026-08-20 09:30:00',
        ])->assertRedirect();
        $risk = ReputationRisk::query()->sole();
        $this->assertSame($store->id, $risk->store_id);

        $this->actingAs($admin)->withSession($session)->patch(route('reputation.risks.update', $risk), [
            'status' => 'resolved',
        ])->assertRedirect();
        $this->assertSame('resolved', $risk->refresh()->status);
        $this->assertNotNull($risk->resolved_at);

        $this->actingAs($admin)->withSession($session)->post(route('reputation.resources.store'), [
            'description' => 'Prepare an official response asset.',
            'request_type' => 'content',
            'priority' => 'important',
            'owner_name' => 'Brand team',
            'due_date' => '2026-08-28',
        ])->assertRedirect();
        $this->assertSame($store->id, ReputationResourceRequest::query()->sole()->store_id);

        $this->actingAs($admin)->withSession($session)->put(route('reputation.goals.update'), [
            'month' => '2026-08',
            'targets' => ['satisfied_reviews' => 80, 'reddit_views' => 100000, 'reddit_comments' => 300],
        ])->assertRedirect();
        $this->assertSame(3, ReputationGoal::query()->where('store_id', $store->id)->count());
        $this->assertTrue(AuditLog::query()->whereIn('action', [
            'reputation_risk_created', 'reputation_risk_updated', 'reputation_resource_created', 'reputation_goals_updated',
        ])->count() >= 4);

        $otherStore = $organization->stores()->create([
            'name' => 'Other Store', 'shopify_domain' => 'other-risk.myshopify.com', 'status' => 'active', 'timezone' => 'UTC',
        ]);
        $otherRisk = ReputationRisk::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $otherStore->id,
            'origin' => 'manual',
            'description' => 'Other store risk',
            'severity' => 'low',
            'source' => 'multiple',
            'status' => 'pending',
        ]);
        $this->actingAs($admin)->withSession($session)
            ->patch(route('reputation.risks.update', $otherRisk), ['status' => 'resolved'])
            ->assertNotFound();

        [$operator, $operatorOrganization, $operatorStore] = $this->context('operator', 'operator-scope');
        $this->actingAs($operator)->withSession($this->contextSession($operatorOrganization, $operatorStore))
            ->post(route('reputation.risks.store'), [
                'description' => 'No write permission', 'severity' => 'low', 'source' => 'multiple',
            ])->assertForbidden();
    }

    public function test_manual_sync_only_queues_current_project_background_job(): void
    {
        Queue::fake();
        [$admin, $organization, $store] = $this->context('organization-admin');
        $this->credential($organization, $store, $admin, 'reputation_wiki_node', 'current-project-wiki-token');

        $this->actingAs($admin)
            ->withSession($this->contextSession($organization, $store))
            ->post(route('reputation.sync'))
            ->assertRedirect();

        $run = ReputationSyncRun::query()->sole();
        $this->assertSame($organization->id, $run->organization_id);
        $this->assertSame($store->id, $run->store_id);
        $this->assertSame('queued', $run->status);
        Queue::assertPushed(SyncReputationForStore::class, fn (SyncReputationForStore $job) => $job->organizationId === $organization->id && $job->storeId === $store->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'reputation_sync_requested', 'store_id' => $store->id]);
    }

    public function test_manual_review_is_scoped_audited_masked_and_follow_up_can_be_updated(): void
    {
        [$admin, $organization, $store] = $this->context('organization-admin');
        $session = $this->contextSession($organization, $store);

        $this->actingAs($admin)->withSession($session)->post(route('reputation.mentions.store'), [
            'source' => 'website',
            'content' => 'The brake needs inspection.',
            'rating' => 2,
            'published_at' => '2026-08-24',
            'model_name' => 'X2',
            'order_reference' => 'ORDER-123456',
            'processing_status' => 'pending',
            'response_note' => 'Customer service will call the customer.',
        ])->assertRedirect();

        $mention = ReputationMention::query()->sole();
        $this->assertSame('manual', $mention->origin);
        $this->assertSame('ORDER-123456', $mention->order_reference_encrypted);
        $this->assertNotSame('ORDER-123456', $mention->getRawOriginal('order_reference_encrypted'));
        $this->assertDatabaseHas('reputation_risks', ['store_id' => $store->id, 'reputation_mention_id' => $mention->id, 'origin' => 'manual']);
        $this->assertDatabaseHas('audit_logs', ['store_id' => $store->id, 'action' => 'reputation_mention_created']);

        $this->actingAs($admin)->withSession($session)
            ->get(route('reputation.overview', ['date_from' => '2026-08-01', 'date_to' => '2026-08-31', 'tab' => 'reviews']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.records.data.0.origin', 'manual')
                ->where('dashboard.records.data.0.order_reference_masked', '••••3456')
                ->missing('dashboard.records.data.0.order_reference_encrypted'));

        $this->actingAs($admin)->withSession($session)->patch(route('reputation.mentions.update', $mention), [
            'processing_status' => 'resolved',
            'response_note' => 'Resolved after replacement.',
        ])->assertRedirect();
        $this->assertSame('resolved', $mention->refresh()->processing_status);
        $this->assertSame('Resolved after replacement.', $mention->response_note);
        $this->assertNotSame('Resolved after replacement.', $mention->getRawOriginal('response_note'));
        $this->assertDatabaseHas('audit_logs', ['store_id' => $store->id, 'action' => 'reputation_mention_updated']);

        $otherStore = $organization->stores()->create([
            'name' => 'Other Mention Store', 'shopify_domain' => 'other-mention.myshopify.com', 'status' => 'active', 'timezone' => 'UTC',
        ]);
        $otherMention = $this->mention($organization, $otherStore, 'website', 'other-mention', ['rating' => 2]);
        $this->actingAs($admin)->withSession($session)
            ->patch(route('reputation.mentions.update', $otherMention), ['processing_status' => 'resolved'])
            ->assertNotFound();
    }

    public function test_source_sync_maps_actual_columns_deduplicates_and_excludes_ai_fields(): void
    {
        [$admin, $organization, $store] = $this->context('organization-admin');
        $this->credential($organization, $store, $admin, 'reputation_wiki_node', 'wiki-main');
        $run = ReputationSyncRun::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'requested_by' => $admin->id,
            'source' => 'manual',
            'status' => 'running',
        ]);
        $manual = $this->mention($organization, $store, 'website', 'manual-preserved', [
            'origin' => 'manual', 'rating' => 4, 'content' => 'Manual record must survive source refresh.',
        ]);

        $this->mock(FeishuBitableClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('wikiNode')->once()->with('wiki-main')->andReturn(['obj_type' => 'sheet', 'obj_token' => 'sheet-main']);
            $mock->shouldReceive('spreadsheetSheets')->once()->with('sheet-main')->andReturn([
                ['title' => 'Trustpilot', 'sheet_id' => 'trustpilot', 'grid_properties' => ['row_count' => 3, 'column_count' => 4]],
                ['title' => 'reddit AI', 'sheet_id' => 'reddit', 'grid_properties' => ['row_count' => 2, 'column_count' => 11]],
                ['title' => 'Threads', 'sheet_id' => 'threads', 'grid_properties' => ['row_count' => 2, 'column_count' => 8]],
                ['title' => 'negative review', 'sheet_id' => 'negative', 'grid_properties' => ['row_count' => 2, 'column_count' => 5]],
            ]);
            $mock->shouldReceive('spreadsheetValues')->once()->with('sheet-main', 'trustpilot', 3, 4)->andReturn([
                ['日期', '评论', '星级', '周数'],
                ['2026-08-20', 'Great ride', 5, 'W34'],
                ['2026-08-21', 'Brake problem', 1, 'W34'],
            ]);
            $mock->shouldReceive('spreadsheetValues')->once()->with('sheet-main', 'reddit', 2, 11)->andReturn([
                ['发帖链接', '发帖日期', '发帖内容', '发帖标题', '发帖 views', 'Upvotes', '发帖评论', '发帖花费', '帖子类型', '目标关键词', '内容效率分（Heat Score）'],
                ['https://reddit.com/r/ebikes/post-1?utm_source=test https://www.reddit.com/r/ebikes/post-1', '2026-08-22', 'Community post', 'Launch', 1200, 45, 13, 80, 'AI value', 'secret keyword', 99],
            ]);
            $mock->shouldReceive('spreadsheetValues')->once()->with('sheet-main', 'threads', 2, 8)->andReturn([
                ['链接', '发布日期', '帖子正文', 'Likes', 'Replies', 'Reposts', 'Shares', '数据抓取'],
                ['https://threads.net/post-1', '2026-08-23', 'Threads post', 20, 4, 3, 2, 'complete'],
            ]);
            $mock->shouldReceive('spreadsheetValues')->once()->with('sheet-main', 'negative', 2, 5)->andReturn([
                ['链接', '差评时间', '差评内容', '备注', '讨论内容'],
                ['https://example.com/risk-1', '2026-08-23', 'Independent risk item', 'Follow up', 'Ongoing discussion'],
            ]);
        });

        $result = app(ReputationSyncService::class)->sync($store, $run);

        $this->assertSame(5, $result['mentions']);
        $this->assertSame(6, ReputationMention::query()->where('store_id', $store->id)->count());
        $this->assertTrue($manual->refresh()->is_active);
        $this->assertSame(2, ReputationRisk::query()->where('store_id', $store->id)->count());
        $this->assertDatabaseHas('reputation_mentions', ['store_id' => $store->id, 'source' => 'multiple', 'is_negative' => true]);
        $this->assertDatabaseHas('reputation_mentions', ['store_id' => $store->id, 'source' => 'trustpilot', 'rating' => 5]);
        $reddit = ReputationMention::query()->where('store_id', $store->id)->where('source', 'reddit')->sole();
        $this->assertSame('https://reddit.com/r/ebikes/post-1', $reddit->url);
        $this->assertSame(1200.0, (float) $reddit->metrics['views']);
        $dashboard = app(ReputationDashboardService::class)->overview($store, [
            'date_from' => '2026-08-01', 'date_to' => '2026-08-31', 'tab' => 'reddit',
        ]);
        $this->assertSame(['Reddit 指标'], $dashboard['records']->items()[0]['source_sheets']);
        $payload = collect($reddit->source_payloads_encrypted)->first();
        $this->assertArrayNotHasKey('帖子类型', $payload);
        $this->assertArrayNotHasKey('目标关键词', $payload);
        $this->assertArrayNotHasKey('内容效率分（Heat Score）', $payload);
    }

    public function test_user_without_reports_permission_cannot_open_reputation_pages(): void
    {
        [$user, $organization, $store] = $this->context('marketing');
        $session = $this->contextSession($organization, $store);

        $this->actingAs($user)->withSession($session)->get(route('reputation.overview'))->assertForbidden();
        $this->actingAs($user)->withSession($session)->get(route('reputation.risks'))->assertForbidden();
    }

    /** @param array<string, mixed> $overrides */
    private function mention(Organization $organization, Store $store, string $source, string $key, array $overrides = []): ReputationMention
    {
        return ReputationMention::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'source' => $source,
            'canonical_key' => hash('sha256', $key),
            'published_at' => '2026-08-20 12:00:00',
            'metrics' => [],
            'is_negative' => false,
            'is_active' => true,
            'synced_at' => now(),
            ...$overrides,
        ]);
    }

    private function credential(Organization $organization, Store $store, User $actor, string $key, string $value): void
    {
        StoreBusinessCredential::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'provider' => 'feishu_data_links',
            'credential_key' => $key,
            'credential_value' => $value,
            'updated_by' => $actor->id,
        ]);
    }

    /** @return array{User, Organization, Store} */
    private function context(string $roleSlug, string $suffix = 'main'): array
    {
        $this->seed(PermissionSeeder::class);
        $organization = Organization::query()->create(['name' => "Reputation {$suffix}", 'code' => "reputation-{$suffix}"]);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create([
            'name' => "Reputation Store {$suffix}",
            'shopify_domain' => "reputation-{$suffix}.myshopify.com",
            'status' => 'active',
            'timezone' => 'UTC',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);

        return [$user, $organization, $store];
    }

    /** @return array{current_organization_id: int, current_store_id: int} */
    private function contextSession(Organization $organization, Store $store): array
    {
        return ['current_organization_id' => $organization->id, 'current_store_id' => $store->id];
    }
}
