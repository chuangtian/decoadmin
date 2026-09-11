<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\AppInstallation;
use App\Models\Organization;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DecoMarketing\Controllers\PublicController;
use DecoMarketing\Models\Attribution;
use DecoMarketing\Models\Campaign;
use DecoMarketing\Models\CheckoutSnapshot;
use DecoMarketing\Models\Contact;
use DecoMarketing\Models\Delivery;
use DecoMarketing\Models\Enrollment;
use DecoMarketing\Models\Flow;
use DecoMarketing\Models\PopupEvent;
use DecoMarketing\Models\Settings;
use DecoMarketing\Models\Template;
use DecoMarketing\Services\Audiences;
use DecoMarketing\Services\Campaigns;
use DecoMarketing\Services\Catalog;
use DecoMarketing\Services\Contacts;
use DecoMarketing\Services\Engine;
use DecoMarketing\Services\Guard;
use DecoMarketing\Services\Health;
use DecoMarketing\Services\Management;
use DecoMarketing\Services\PopupAnalytics;
use DecoMarketing\Services\Renderer;
use DecoMarketing\Services\Reports;
use DecoMarketing\Services\Shopify;
use DecoMarketing\Services\Tokens;
use DecoMarketing\Services\Transport;
use DecoMarketing\Services\Warmup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MarketingTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['marketing.environment' => 'test', 'marketing.transport' => 'preview', 'inertia.ssr.enabled' => false]);
        Http::preventStrayRequests();
        $org = Organization::create(['name' => 'Marketing test', 'code' => 'marketing-test', 'status' => 'active']);
        $this->store = $org->stores()->create(['name' => 'macfox-test-app', 'shopify_domain' => Guard::SHOP, 'status' => 'active', 'currency' => 'USD']);
        $this->actor = User::factory()->create(['status' => 'active', 'metadata' => ['is_super_admin' => true], 'email_verified_at' => now()]);
        app(Catalog::class)->setup($this->store);
        Settings::forStore($this->store)->first()->update(['enabled' => true, 'cutover_at' => now()->subDays(30)]);
        Flow::forStore($this->store)->where('key', 'welcome')->first()->update(['enabled' => true, 'steps' => [['template' => 'welcome_1', 'after_minutes' => 0], ['template' => 'welcome_2', 'after_minutes' => 60]]]);
    }

    private function contact(string $email = 'jiushizheyike@gmail.com'): Contact
    {
        return app(Contacts::class)->upsert($this->store, ['email' => $email, 'name' => 'Marketing test', 'consent' => 'subscribed', 'consent_at' => now()->subMinute()->toIso8601String()], 'import');
    }

    private function enroll(?Contact $contact = null): Enrollment
    {
        return app(Engine::class)->enroll($this->store, $contact ?? $this->contact(), 'welcome', 'unique-test-source');
    }

    private function base(?Store $store = null): string
    {
        $s = $store ?? $this->store;

        return '/organizations/'.$s->organization_id.'/stores/'.$s->id.'/marketing';
    }

    public function test_shared_customer_reuse_preserves_withdrawal_and_updates_incrementally(): void
    {
        $contact = $this->contact(); $contact->update(['consent' => 'unsubscribed', 'suppressed' => true]);
        $row = \App\Models\Customer::create(['organization_id' => $this->store->organization_id, 'store_id' => $this->store->id, 'shopify_customer_id' => '123456', 'email' => $contact->email_encrypted, 'created_at_shopify' => now()->subDay(), 'updated_at_shopify' => now(), 'synced_at' => now(), 'first_name' => 'Existing', 'orders_count' => 3, 'total_spent' => '100.00']);
        app(\DecoMarketing\Services\SharedData::class)->sync($this->store);
        $this->assertSame('unsubscribed', $contact->fresh()->consent);
        $this->assertTrue($contact->fresh()->suppressed);
        $this->assertSame(3, $contact->fresh()->orders_count);
        $row->update(['orders_count' => 4]);
        app(\DecoMarketing\Services\SharedData::class)->sync($this->store);
        $this->assertSame(4, $contact->fresh()->orders_count);
        $this->assertSame(1, Contact::forStore($this->store)->count());
        $this->assertNotEmpty(Settings::forStore($this->store)->sole()->sync_state['shared_customers']['since']);
    }

    public function test_orders_are_retained_before_customers_and_without_email(): void
    {
        $node = ['id' => 'gid://shopify/Order/901', 'name' => '#901', 'email' => 'first-order@example.invalid', 'customer' => ['id' => 'gid://shopify/Customer/902'], 'createdAt' => now()->subDay()->toIso8601String(), 'displayFinancialStatus' => 'PAID', 'totalPriceSet' => ['shopMoney' => ['amount' => '99.00', 'currencyCode' => 'USD']]];
        app(Shopify::class)->order($this->store, $node);
        $order = \DecoMarketing\Models\OrderSnapshot::forStore($this->store)->firstOrFail();
        $this->assertNotNull($order->contact_id);
        $this->assertSame('not_subscribed', Contact::findOrFail($order->contact_id)->consent);
        $node['id'] = 'gid://shopify/Order/903'; $node['email'] = null; $node['customer'] = null;
        app(Shopify::class)->order($this->store, $node);
        $this->assertSame(2, \DecoMarketing\Models\OrderSnapshot::forStore($this->store)->count());
        $this->assertNull(\DecoMarketing\Models\OrderSnapshot::forStore($this->store)->where('order_id', $node['id'])->sole()->contact_id);
        app(Shopify::class)->order($this->store, $node);
        $this->assertSame(2, \DecoMarketing\Models\OrderSnapshot::forStore($this->store)->count());
    }

    public function test_reconciliation_store_is_opt_in_and_can_never_send_real_mail(): void
    {
        $s = $this->store->organization->stores()->create(['name' => 'Macfox Bike', 'shopify_domain' => Guard::RECONCILIATION_SHOP, 'status' => 'active', 'currency' => 'USD']);
        try { app(Guard::class)->store($s); $this->fail('Default must reject production store.'); } catch (HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        config(['marketing.reconciliation' => true, 'marketing.transport' => 'system']);
        app(Guard::class)->store($s);
        $d = new Delivery(['organization_id' => $s->organization_id, 'store_id' => $s->id, 'payload_encrypted' => ['to' => 'jiushizheyike@gmail.com']]);
        $this->assertSame('simulated', app(Transport::class)->send($s, $d)['status']);
        config(['marketing.transport' => 'resend']);
        $this->assertSame('simulated', app(Transport::class)->send($s, $d)['status']);
        try { app(Shopify::class)->query($s, 'mutation { shop { id } }'); $this->fail('Mutation must be rejected before token access.'); } catch (HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
    }

    public function test_attribution_rows_include_source_and_scoped_unpaid_totals(): void
    {
        $c = $this->contact();
        $e = $this->enroll($c);
        $d = Delivery::create(['organization_id' => $this->store->organization_id, 'store_id' => $this->store->id, 'contact_id' => $c->id, 'enrollment_id' => $e->id, 'dedupe_key' => 'report-detail-test', 'clicked_at' => now()->subMinutes(15), 'payload_encrypted' => ['subject' => 'Source message']]);
        Attribution::create(['organization_id' => $this->store->organization_id, 'store_id' => $this->store->id, 'order_id' => 'gid://shopify/Order/QA1', 'delivery_id' => $d->id, 'revenue' => 100, 'currency' => 'USD', 'status' => 'paid', 'ordered_at' => now()]);
        \DecoMarketing\Models\OrderSnapshot::create(['organization_id' => $this->store->organization_id, 'store_id' => $this->store->id, 'order_id' => 'gid://shopify/Order/QA2', 'contact_id' => $c->id, 'name' => 'Unpaid test', 'financial_status' => 'PENDING', 'currency' => 'USD', 'total' => 50, 'refunded' => 0, 'outstanding' => 50, 'ordered_at' => now()]);
        $row = app(\DecoMarketing\Services\AttributionRows::class)->page($this->store, 30)->items()[0];
        $this->assertSame('Source message', $row['subject']);
        $this->assertSame($c->email_encrypted, $row['email']);
        $this->assertSame('welcome', $row['flow']);
        $this->assertSame(15, $row['click_minutes']);
        $this->assertSame(1, $row['pending'][0]['count']);
        $this->assertEquals(50, $row['pending'][0]['amount']);
    }

    public function test_email_products_escape_content_and_reject_untrusted_images(): void
    {
        $rendered = app(Renderer::class)->render($this->store, $this->contact(), ['subject' => 'Test', 'body' => '<script>bad</script>', 'button' => 'View'], ['products' => [
            ['title' => '<b>Bike</b>', 'quantity' => 2, 'price' => '99.00', 'currency' => 'USD', 'image' => 'https://untrusted.example/image.png'],
            ['title' => 'Safe bike', 'quantity' => 1, 'image' => 'https://cdn.shopify.com/s/files/test.png'],
        ]]);
        $this->assertStringNotContainsString('<script>', $rendered['html']);
        $this->assertStringNotContainsString('untrusted.example', $rendered['html']);
        $this->assertStringContainsString('&lt;b&gt;Bike&lt;/b&gt;', $rendered['html']);
        $this->assertStringContainsString('https://cdn.shopify.com/s/files/test.png', $rendered['html']);
        $this->assertStringContainsString('2 × 99.00 USD', $rendered['text']);
    }

    public function test_coupon_outage_preserves_step_and_retries_without_sending(): void
    {
        $e = $this->enroll();
        $steps = $e->steps;
        $steps[0]['content']['body'] = 'Use {couponCode}';
        $e->update(['steps' => $steps]);
        Settings::forStore($this->store)->first()->update(['welcome_coupon' => 'QA-CODE']);
        $this->mock(\DecoMarketing\Services\Coupons::class)->shouldReceive('check')->once()->andReturn(['status' => 'unavailable']);
        app(Engine::class)->run($this->store);
        $this->assertSame('active', $e->fresh()->status);
        $this->assertSame(0, $e->fresh()->step);
        $this->assertTrue($e->fresh()->next_at->isFuture());
        $d = Delivery::forStore($this->store)->firstOrFail();
        $this->assertSame(0, $d->attempts);
        $this->assertSame('coupon_verification_unavailable', $d->reason);
    }

    public function test_smart_send_window_handles_local_time_and_dst(): void
    {
        $w=app(\DecoMarketing\Services\SendWindow::class);
        $inside=CarbonImmutable::parse('2026-03-08 16:30:00','UTC');
        $this->assertTrue($inside->eq($w->next($inside,'America/Los_Angeles','same-key')));
        $after=CarbonImmutable::parse('2026-03-08 19:00:00','UTC');
        $next=$w->next($after,'America/Los_Angeles','same-key');
        $this->assertSame('2026-03-09',$next->setTimezone('America/Los_Angeles')->toDateString());
        $this->assertGreaterThanOrEqual(9,$next->setTimezone('America/Los_Angeles')->hour);
        $this->assertLessThan(12,$next->setTimezone('America/Los_Angeles')->hour);
        $this->assertTrue($next->eq($w->next($after,'America/Los_Angeles','same-key')));
    }

    public function test_workspace_header_uses_route_store_instead_of_previous_selection(): void
    {
        $other=$this->store->organization->stores()->create(['name'=>'Other header fixture','shopify_domain'=>'other-fixture.myshopify.com','status'=>'active']);
        $this->actingAs($this->actor)->withSession(['current_store_id'=>$other->id,'current_organization_id'=>$other->organization_id])->get($this->base().'?tab=logs')->assertOk()
            ->assertInertia(fn(Assert $p)=>$p->where('currentStore.id',$this->store->id)->where('currentStore.name','macfox-test-app'));
    }

    public function test_all_legacy_templates_render_and_staging_preserves_published_content(): void
    {
        $legacy=app(\DecoMarketing\Services\LegacyTemplates::class);
        $this->assertCount(12,$legacy->contents());
        $c=$this->contact();
        foreach($legacy->contents()as$content){
            $rendered=app(Renderer::class)->render($this->store,$c,$content,['order_name'=>'TEST','product_title'=>'Test bike','coupon_code'=>'TEST-COUPON','referral_code'=>'TEST-REFERRAL']);
            $this->assertStringNotContainsString('{referralCode}',$rendered['html']);
            $this->assertStringContainsString('Unsubscribe',$rendered['html']);
        }
        $before=Template::forStore($this->store)->pluck('published','key')->all();
        $legacy->stage($this->store);
        $this->assertEquals($before,Template::forStore($this->store)->pluck('published','key')->all());
        $this->assertSame(0,$legacy->stage($this->store));
        $this->expectException(ValidationException::class);
        app(Renderer::class)->render($this->store,$c,$legacy->contents()['advocacy'],[]);
    }

    public function test_payment_coverage_compares_clicked_and_not_clicked_orders(): void
    {
        $c=$this->contact();
        foreach(['paid','pending'] as $status){
            $e=Enrollment::create(['organization_id'=>$this->store->organization_id,'store_id'=>$this->store->id,'contact_id'=>$c->id,'flow_key'=>'payment','source_key'=>'QA-'.$status,'status'=>'completed','steps'=>[]]);
            Delivery::create(['organization_id'=>$this->store->organization_id,'store_id'=>$this->store->id,'contact_id'=>$c->id,'enrollment_id'=>$e->id,'kind'=>'automation','dedupe_key'=>hash('sha256','coverage-'.$status),'status'=>'sent','sent_at'=>now()->subDay(),'clicked_at'=>$status==='paid'?now():null]);
            \DecoMarketing\Models\OrderSnapshot::create(['organization_id'=>$this->store->organization_id,'store_id'=>$this->store->id,'contact_id'=>$c->id,'order_id'=>'QA-'.$status,'name'=>'QA-'.$status,'financial_status'=>strtoupper($status),'currency'=>'USD','total'=>100,'outstanding'=>$status==='paid'?0:40,'ordered_at'=>now()->subDays(2)]);
        }
        $r=app(\DecoMarketing\Services\ConversionReports::class)->report($this->store)['coverage'][0];
        $this->assertEquals(100,$r['comparison']['clicked']['rate']);
        $this->assertEquals(0,$r['comparison']['not_clicked']['rate']);
        $this->assertEquals(40,$r['outstanding']);
        $this->assertNull($r['p_value']);
    }

    public function test_log_flow_filter_separates_real_flows_and_tests(): void
    {
        $c=$this->contact();$e=$this->enroll($c);
        foreach(['automation','template_test'] as $kind)Delivery::create(['organization_id'=>$this->store->organization_id,'store_id'=>$this->store->id,'contact_id'=>$c->id,'enrollment_id'=>$kind==='automation'?$e->id:null,'kind'=>$kind,'dedupe_key'=>hash('sha256','flow-filter-'.$kind),'status'=>'sent','sent_at'=>now(),'opened_at'=>now()]);
        $this->actingAs($this->actor)->get($this->base().'?tab=logs&flow=welcome')->assertOk()->assertInertia(fn(Assert $p)=>$p->where('rows.total',1)->where('rows.data.0.flow','welcome')->where('filters.flow','welcome'));
        $this->get($this->base().'?tab=logs&flow=test')->assertOk()->assertInertia(fn(Assert $p)=>$p->where('rows.total',1)->where('rows.data.0.flow','template_test'));
        $this->get($this->base().'?tab=logs&flow=payment')->assertOk()->assertInertia(fn(Assert $p)=>$p->where('rows.total',0));
    }

    public function test_ab_uses_filtered_opens_instead_of_clicks(): void
    {
        $campaign=Campaign::create(['organization_id'=>$this->store->organization_id,'store_id'=>$this->store->id,'name'=>'A/B rule test','content'=>['subject'=>'A','body'=>'A','button'=>'Shop'],'variant_b'=>['subject'=>'B','body'=>'B','button'=>'Shop'],'status'=>'sending','scheduled_at'=>now()->subDays(2),'expanded_at'=>now()->subDays(2),'test_ends_at'=>now()->subMinute()]);
        foreach(['A','B','hold'] as $variant){
            $c=$this->contact('ab-'.$variant.'@example.invalid');
            $e=Enrollment::create(['organization_id'=>$this->store->organization_id,'store_id'=>$this->store->id,'contact_id'=>$c->id,'campaign_id'=>$campaign->id,'flow_key'=>'campaign','source_key'=>$campaign->uuid,'variant'=>$variant,'status'=>$variant==='hold'?'waiting':'completed','steps'=>[]]);
            if($variant!=='hold') Delivery::create(['organization_id'=>$this->store->organization_id,'store_id'=>$this->store->id,'contact_id'=>$c->id,'enrollment_id'=>$e->id,'kind'=>'automation','dedupe_key'=>hash('sha256','ab-'.$variant),'status'=>'sent','sent_at'=>now()->subDay(),'clicked_at'=>$variant==='A'?now():null,'human_opened_at'=>$variant==='B'?now():null]);
        }
        app(Campaigns::class)->expand($this->store);
        $this->assertSame('B',$campaign->fresh()->winner);
        $this->assertSame(1,Enrollment::forStore($this->store)->where('campaign_id',$campaign->id)->where('status','active')->where('variant','B')->count());
    }

    public function test_conversion_keeps_first_subscription_after_withdrawal(): void
    {
        $c=$this->contact();$c->update(['first_subscribed_at'=>now()->subDays(20)]);
        app(Contacts::class)->unsubscribe($c);
        \DecoMarketing\Models\OrderSnapshot::create(['organization_id'=>$this->store->organization_id,'store_id'=>$this->store->id,'contact_id'=>$c->id,'order_id'=>'QA-ORDER','name'=>'QA-ORDER','financial_status'=>'PAID','currency'=>'USD','total'=>100,'ordered_at'=>now()->subDays(18)]);
        $r=app(\DecoMarketing\Services\ConversionReports::class)->report($this->store);
        $this->assertSame(1,$r['cohort']['not_received']['people']);
        $this->assertSame(1,$r['cohort']['not_received']['converted']);
        $this->assertNull($r['p_value']);
    }

    public function test_tracking_distinguishes_prefetch_unknown_and_filtered_opens(): void
    {
        $c = $this->contact();
        $d = Delivery::create(['organization_id' => $this->store->organization_id, 'store_id' => $this->store->id, 'contact_id' => $c->id, 'kind' => 'automation', 'dedupe_key' => hash('sha256', 'open-classification'), 'sent_at' => now()->subMinutes(2), 'status' => 'sent']);
        $url = URL::signedRoute('marketing.open', ['delivery' => $d->uuid]);
        $this->get('/marketing-public/open/'.$d->uuid)->assertForbidden();
        $this->withHeader('User-Agent', 'GoogleImageProxy')->get($url)->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->assertNotNull($d->fresh()->machine_opened_at);
        $this->assertNull($d->fresh()->human_opened_at);
        $this->withHeader('User-Agent', 'AppleWebKit/605.1.15')->get($url)->assertOk();
        $this->assertNull($d->fresh()->human_opened_at);
        $this->withHeader('User-Agent', 'Mozilla/5.0 Chrome/130.0')->get($url)->assertOk();
        $at = $d->fresh()->human_opened_at;
        $this->assertNotNull($at);
        $this->withHeader('User-Agent', 'Mozilla/5.0 Chrome/130.0')->get($url)->assertOk();
        $this->assertTrue($at->eq($d->fresh()->human_opened_at));
        $this->assertSame(100.0, (float) app(Reports::class)->overview($this->store)['human_open_rate']);
    }

    public function test_warmup_is_explicit_read_only_on_report_and_increases_only_when_enabled(): void
    {
        $settings = Settings::forStore($this->store)->first();
        $settings->update(['daily_limit' => 100, 'cutover_at' => now()->subDays(9), 'warmup_steps' => [300, 800, 5000]]);
        $service = app(Warmup::class);
        $this->assertSame(800, $service->report($this->store)['target']);
        $service->advance($this->store);
        $this->assertSame(100, $settings->fresh()->daily_limit);
        $settings->update(['warmup_enabled' => true]);
        $service->advance($this->store);
        $this->assertSame(800, $settings->fresh()->daily_limit);
        $settings->update(['daily_limit' => 1000]);
        $service->advance($this->store);
        $this->assertSame(1000, $settings->fresh()->daily_limit);
    }

    public function test_smtp_unknown_attempt_is_held_without_network_retry(): void
    {
        config(['marketing.transport' => 'system']);
        $c = $this->contact();
        $d = Delivery::create(['organization_id' => $this->store->organization_id, 'store_id' => $this->store->id, 'contact_id' => $c->id, 'kind' => 'template_test', 'dedupe_key' => hash('sha256', 'smtp-held'), 'attempts' => 2, 'payload_encrypted' => ['to' => $c->email_encrypted, 'from' => 'test@example.com']]);
        $this->assertSame('held', app(Transport::class)->send($this->store, $d)['status']);
        Http::assertNothingSent();
    }

    public function test_health_uses_california_recipient_cohort_and_email_withdrawals(): void
    {
        Carbon::setTestNow('2026-09-10 08:00:00');
        CarbonImmutable::setTestNow('2026-09-10 08:00:00');
        try {
            $contact = $this->contact();
            $base = ['organization_id' => $this->store->organization_id, 'store_id' => $this->store->id, 'contact_id' => $contact->id, 'kind' => 'automation', 'status' => 'sent'];
            foreach (['2026-09-09 06:59:59', '2026-09-09 07:00:00', '2026-09-10 06:59:59', '2026-09-10 07:00:00'] as $i => $at) {
                Delivery::create([...$base, 'dedupe_key' => hash('sha256', 'cohort-'.$i), 'sent_at' => $at]);
            }
            // Duplicate sends to one recipient must not inflate the unsubscribe denominator.
            Delivery::create([...$base, 'kind' => 'template_test', 'dedupe_key' => hash('sha256', 'test-mail'), 'sent_at' => '2026-09-09 10:00:00', 'unsubscribed_at' => now()]);
            $report = app(Health::class)->report($this->store);
            $this->assertSame('2026-09-09', $report['yesterday']);
            $this->assertSame(2, $report['sent']);
            $this->assertSame(1, $report['recipients']);
            $this->assertSame(0, $report['unsubscribed']);
            Delivery::where('dedupe_key', hash('sha256', 'cohort-3'))->update(['unsubscribed_at' => now()]);
            $report = app(Health::class)->report($this->store);
            $this->assertSame(1, $report['unsubscribed']);
            $this->assertEquals(100, $report['unsubscribe_rate']);
            $this->assertNull($report['human_open_rate']);
            $this->actingAs($this->actor)->get($this->base().'?tab=health')->assertOk();
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }

    public function test_campaign_health_counts_people_and_excludes_test_messages(): void
    {
        $contact = $this->contact();
        $campaign = Campaign::create(['organization_id' => $this->store->organization_id, 'store_id' => $this->store->id, 'name' => 'Health boundary', 'content' => ['subject' => 'Test', 'body' => 'Test', 'button' => 'Shop']]);
        $enrollment = $this->enroll($contact);
        $enrollment->update(['campaign_id' => $campaign->id]);
        $base = ['organization_id' => $this->store->organization_id, 'store_id' => $this->store->id, 'contact_id' => $contact->id, 'enrollment_id' => $enrollment->id, 'kind' => 'automation', 'status' => 'sent', 'sent_at' => now()->subHour()];
        $rows = [];
        for ($i = 0; $i < 500; $i++) {
            $rows[] = [...$base, 'uuid' => (string) Str::uuid(), 'dedupe_key' => hash('sha256', 'campaign-health-'.$i)];
        }
        Delivery::insert($rows);
        Delivery::where('dedupe_key', hash('sha256', 'campaign-health-0'))->update(['unsubscribed_at' => now()]);
        Delivery::where('dedupe_key', hash('sha256', 'campaign-health-1'))->update(['unsubscribed_at' => now()]);
        Delivery::create([...$base, 'kind' => 'template_test', 'dedupe_key' => hash('sha256', 'campaign-test')]);
        $report = app(Health::class)->campaign($this->store, $campaign);
        $this->assertSame(500, $report['sent']);
        $this->assertSame(1, $report['unsubscribed']);
        $this->assertFalse($report['exceeded']);
        $this->assertTrue($report['eligible']);
        // At exactly 0.50% the campaign is on the line; only exceeding it pauses.
        for ($i = 500; $i < 600; $i++) {
            Delivery::create([...$base, 'dedupe_key' => hash('sha256', 'campaign-health-'.$i)]);
        }
        for ($i = 1; $i <= 3; $i++) {
            $person = $this->contact('health-'.$i.'@example.invalid');
            Delivery::where('dedupe_key', hash('sha256', 'campaign-health-'.($i + 1)))->update(['contact_id' => $person->id, 'unsubscribed_at' => $i < 3 ? now() : null]);
        }
        $this->assertFalse(app(Campaigns::class)->unhealthy($this->store, $campaign));
        Delivery::where('dedupe_key', hash('sha256', 'campaign-health-4'))->update(['unsubscribed_at' => now()]);
        $this->assertTrue(app(Campaigns::class)->unhealthy($this->store, $campaign));
    }

    public function test_preview_has_no_network_and_does_not_count_as_sent(): void
    {
        $e = $this->enroll();
        app(Engine::class)->run($this->store);
        app(Engine::class)->run($this->store);
        $this->assertDatabaseCount('marketing_deliveries', 1);
        $d = Delivery::sole();
        $this->assertSame('simulated', $d->status);
        $this->assertNull($d->sent_at);
        $this->assertSame(1, $e->fresh()->step);
        $this->assertNull($e->contact->fresh()->last_sent_at);
        Http::assertNothingSent();
    }

    public function test_same_event_enrolls_once_and_next_step_is_durable(): void
    {
        $c = $this->contact();
        $e = $this->enroll($c);
        $again = $this->enroll($c);
        $this->assertSame($e->id, $again->id);
        app(Engine::class)->run($this->store);
        $this->travel(61)->minutes();
        app(Engine::class)->run($this->store);
        $this->assertSame('completed', $e->fresh()->status);
        $this->assertDatabaseCount('marketing_deliveries', 2);
        $this->assertNotSame(Delivery::first()->dedupe_key, Delivery::latest('id')->first()->dedupe_key);
    }

    public function test_live_retry_keeps_exact_payload_and_idempotency_key(): void
    {
        config(['marketing.transport' => 'resend', 'marketing.resend_key' => 'fake', 'marketing.from' => 'test@example.com']);
        $e = $this->enroll();
        $keys = [];
        $bodies = [];
        Http::fake(function ($request) use (&$keys, &$bodies) {
            $keys[] = $request->header('Idempotency-Key')[0];
            $bodies[] = $request->body();

            return count($keys) === 1 ? Http::response([], 503) : Http::response(['id' => 'provider-1'], 200);
        });
        app(Engine::class)->run($this->store);
        $this->assertSame('uncertain', Delivery::sole()->status);
        config(['marketing.from' => 'changed@example.com']);
        $this->travel(6)->minutes();
        app(Engine::class)->run($this->store);
        $this->assertSame('sent', Delivery::sole()->status);
        $this->assertSame($keys[0], $keys[1]);
        $this->assertSame($bodies[0], $bodies[1]);
        $this->assertSame(1, $e->fresh()->step);
    }

    public function test_unknown_outcome_after_window_is_held_without_resend(): void
    {
        config(['marketing.transport' => 'resend', 'marketing.resend_key' => 'fake', 'marketing.from' => 'test@example.com']);
        Http::fake(['api.resend.com/*' => Http::response([], 503)]);
        $e = $this->enroll();
        app(Engine::class)->run($this->store);
        $this->travel(24)->hours();
        app(Engine::class)->run($this->store);
        $this->assertSame('held', Delivery::sole()->status);
        $this->assertSame('held', $e->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_legacy_retry_without_sender_snapshot_is_held(): void
    {
        config(['marketing.transport' => 'resend', 'marketing.resend_key' => 'fake', 'marketing.from' => 'test@example.com']);
        Http::fake(['api.resend.com/*' => Http::response([], 503)]);
        $this->enroll();
        app(Engine::class)->run($this->store);
        $delivery = Delivery::sole();
        $payload = $delivery->payload_encrypted;
        unset($payload['from']);
        $delivery->update(['payload_encrypted' => $payload]);
        $this->travel(6)->minutes();
        app(Engine::class)->run($this->store);
        $this->assertSame('held', $delivery->fresh()->status);
        $this->assertSame('sender_snapshot_missing', $delivery->fresh()->reason);
        Http::assertSentCount(1);
    }

    public function test_refresh_uses_only_marketing_credentials_and_retains_connection_token(): void
    {
        config(['marketing.active.client_id' => 'marketing-client', 'marketing.active.client_secret' => 'marketing-secret']);
        $connection = ShopifyConnection::create(['store_id' => $this->store->id, 'shop_domain' => Guard::SHOP, 'status' => 'connected', 'access_token_encrypted' => 'other-token', 'api_version' => '2026-07', 'scopes' => ['read_orders']]);
        $app = App::create(['name' => 'Marketing', 'handle' => config('marketing.active.handle'), 'client_id' => 'marketing-client', 'status' => 'active']);
        $installation = AppInstallation::create(['app_id' => $app->id, 'store_id' => $this->store->id, 'shopify_connection_id' => $connection->id, 'status' => 'active', 'granted_scopes' => ['read_orders'], 'settings' => ['environment' => 'test'],
            'access_token_encrypted' => 'marketing-old', 'access_token_expires_at' => now()->subMinute(), 'refresh_token_encrypted' => 'marketing-refresh', 'refresh_token_expires_at' => now()->addDay()]);
        Http::fake(['https://'.Guard::SHOP.'/admin/oauth/access_token' => Http::response(['access_token' => 'marketing-new', 'refresh_token' => 'marketing-refresh-new', 'expires_in' => 3600, 'refresh_token_expires_in' => 86400], 200)]);
        $this->assertSame('marketing-new', app(Tokens::class)->access($this->store));
        $this->assertSame('marketing-new', app(Tokens::class)->access($this->store));
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['client_id'] === 'marketing-client' && $r['client_secret'] === 'marketing-secret' && $r['refresh_token'] === 'marketing-refresh');
        $this->assertSame('other-token', $connection->fresh()->access_token_encrypted);
        $this->assertSame('marketing-refresh-new', $installation->fresh()->refresh_token_encrypted);
        $installation->refresh()->update(['access_token_expires_at' => now()->subMinute(), 'refresh_token_expires_at' => now()->subMinute()]);
        try {
            app(Tokens::class)->access($this->store);
            $this->fail('Expired refresh authorization must stop.');
        } catch (ValidationException) {
            Http::assertSentCount(1);
            $this->assertSame('other-token', $connection->fresh()->access_token_encrypted);
        }
    }

    public function test_unsubscribe_stops_queued_steps_and_import_cannot_resubscribe(): void
    {
        $c = $this->contact();
        $e = $this->enroll($c);
        app(Contacts::class)->unsubscribe($c);
        $this->travel(1)->minutes();
        $this->contact();
        app(Engine::class)->run($this->store);
        $this->assertSame('unsubscribed', $c->fresh()->consent);
        $this->assertSame('stopped', $e->fresh()->status);
        $this->assertDatabaseCount('marketing_deliveries', 0);
    }

    public function test_paid_order_is_checked_before_send(): void
    {
        $c = $this->contact();
        Flow::forStore($this->store)->where('key', 'payment')->update(['enabled' => true]);
        $e = app(Engine::class)->enroll($this->store, $c, 'payment', 'order-1', ['order_id' => 'gid://shopify/Order/1'], CarbonImmutable::now()->subHours(5));
        $this->mock(Shopify::class, function ($mock) {
            $mock->shouldReceive('stopReason')->once()->andReturn('payment_no_longer_pending');
        });
        app(Engine::class)->run($this->store);
        $this->assertSame('stopped', $e->fresh()->status);
        $this->assertDatabaseCount('marketing_deliveries', 0);
    }

    public function test_unknown_shopify_status_defers_instead_of_sending(): void
    {
        $e = app(Engine::class)->enroll($this->store, $this->contact(), 'welcome', 'known-customer', ['customer_id' => 'gid://shopify/Customer/1']);
        $this->mock(Shopify::class, fn ($mock) => $mock->shouldReceive('stopReason')->once()->andReturn('verification_unavailable'));
        app(Engine::class)->run($this->store);
        $this->assertTrue($e->fresh()->next_at->isFuture());
        $this->assertDatabaseCount('marketing_deliveries', 0);
    }

    public function test_other_shop_is_rejected_even_for_superadmin_and_before_network(): void
    {
        $other = $this->store->organization->stores()->create(['name' => 'Macfox Bike', 'shopify_domain' => 'macfoxebike.myshopify.com', 'status' => 'active']);
        $this->actingAs($this->actor)->post($this->base($other).'/actions/initialize')->assertForbidden();
        $this->actingAs($this->actor)->get($this->base($other))->assertForbidden();
        $this->assertDatabaseMissing('marketing_settings', ['store_id' => $other->id]);
        Http::assertNothingSent();
    }

    public function test_production_environment_is_rejected(): void
    {
        config(['marketing.environment' => 'production']);
        $this->actingAs($this->actor)->post($this->base().'/actions/run')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_users_without_permission_cannot_read_or_write(): void
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $this->store->organization->users()->attach($u, ['status' => 'active']);
        $this->store->members()->attach($u, ['status' => 'active']);
        $this->actingAs($u)->get($this->base())->assertForbidden();
        $this->actingAs($u)->post($this->base().'/actions/initialize')->assertForbidden();
    }

    public function test_contact_pii_is_encrypted_and_not_serialized(): void
    {
        $c = $this->contact();
        $this->assertNotSame($c->email_encrypted, $c->getRawOriginal('email_encrypted'));
        $this->assertArrayNotHasKey('email_encrypted', $c->toArray());
        $this->assertArrayNotHasKey('email_hash', $c->toArray());
    }

    public function test_frequency_and_daily_limit_defer_tasks(): void
    {
        $c = $this->contact();
        $c->update(['last_sent_at' => now()]);
        $e = $this->enroll($c);
        app(Engine::class)->run($this->store);
        $this->assertTrue($e->fresh()->next_at->gte(now()->addHours(47)));
        $this->assertDatabaseCount('marketing_deliveries', 0);
    }

    public function test_switching_preview_to_live_cannot_replay_old_tasks(): void
    {
        $e = $this->enroll();
        config(['marketing.transport' => 'resend']);
        app(Engine::class)->run($this->store);
        $this->assertSame('transport_mode_changed', $e->fresh()->stop_reason);
        Http::assertNothingSent();
    }

    public function test_live_recipient_allowlist_is_enforced(): void
    {
        config(['marketing.transport' => 'resend']);
        $e = $this->enroll($this->contact('not-authorized@example.com'));
        app(Engine::class)->run($this->store);
        $this->assertSame('recipient_not_allowed', $e->fresh()->stop_reason);
        Http::assertNothingSent();
    }

    public function test_cutover_prevents_historical_welcome_backfill(): void
    {
        Settings::forStore($this->store)->first()->update(['cutover_at' => now()]);
        $e = app(Engine::class)->enroll($this->store, $this->contact(), 'welcome', 'old', [], CarbonImmutable::now()->subDay());
        $this->assertNull($e);
    }

    public function test_import_preview_is_read_only_and_bad_rows_are_atomic(): void
    {
        $csv = "email,name,consent,consent_at\nuser@example.com,Name,subscribed,2026-01-01T00:00:00Z";
        $preview = app(Management::class)->importPreview($this->store, $this->actor, ['csv' => $csv]);
        $this->assertSame(1, $preview['total']);
        $this->assertDatabaseCount('marketing_contacts', 0);
        $this->actingAs($this->actor)->post($this->base().'/actions/import', ['csv' => $csv, 'digest' => $preview['digest']])->assertRedirect();
        $this->assertDatabaseCount('marketing_contacts', 1);
        $this->assertDatabaseCount('marketing_enrollments', 0);
        $this->actingAs($this->actor)->post($this->base().'/actions/import', ['csv' => $csv.'x', 'digest' => $preview['digest']])->assertSessionHasErrors();
        $this->assertDatabaseCount('marketing_contacts', 1);
    }

    public function test_templates_escape_html_and_reject_unsafe_destination(): void
    {
        $c = $this->contact();
        $out = app(Renderer::class)->render($this->store, $c, ['subject' => 'Hi {firstName}', 'body' => '<script>alert(1)</script>', 'button' => 'Shop'], []);
        $this->assertStringNotContainsString('<script>', $out['html']);
        $this->assertStringContainsString('&lt;script&gt;', $out['html']);
        $this->expectException(ValidationException::class);
        app(Renderer::class)->destination($this->store, ['url' => 'https://macfoxbike.com']);
    }

    public function test_unsigned_unsubscribe_is_rejected_and_get_does_not_mutate(): void
    {
        $c = $this->contact();
        $url = URL::signedRoute('marketing.unsubscribe', ['contact' => $c->uuid]);
        $this->get('/marketing-public/unsubscribe/'.$c->uuid)->assertForbidden();
        $this->get($url)->assertOk();
        $this->assertSame('subscribed', $c->fresh()->consent);
        $this->post($url)->assertOk();
        $this->assertSame('unsubscribed', $c->fresh()->consent);
    }

    public function test_all_workspace_tabs_are_accessible_without_initialization_side_effects(): void
    {
        foreach (['overview', 'flows', 'campaigns', 'templates', 'contacts', 'waitlist', 'popup', 'logs', 'attribution', 'health'] as $tab) {
            $this->actingAs($this->actor)->get($this->base().'?tab='.$tab)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Marketing/Index', false)->where('tab', $tab));
        }
        Http::assertNothingSent();
    }

    public function test_template_publish_requires_test_of_same_content(): void
    {
        $t = Template::forStore($this->store)->first();
        $this->actingAs($this->actor)->post($this->base().'/actions/template-publish', ['uuid' => $t->uuid])->assertSessionHasErrors();
        $t->update(['tested_hash' => hash('sha256', json_encode($t->draft)), 'tested_at' => now()]);
        $this->actingAs($this->actor)->post($this->base().'/actions/template-publish', ['uuid' => $t->uuid])->assertRedirect();
        $this->assertSame(2, $t->fresh()->version);
        $this->actingAs($this->actor)->post($this->base().'/actions/template', ['uuid' => $t->uuid, 'content' => ['subject' => 'Changed', 'body' => 'New body', 'button' => 'Shop']])->assertRedirect();
        $this->assertNull($t->fresh()->tested_hash);
    }

    public function test_campaign_pause_and_cancel_preserve_data(): void
    {
        $this->contact();
        $this->actingAs($this->actor)->post($this->base().'/actions/campaign', ['name' => 'Test campaign', 'audience' => 'subscribed', 'content' => ['subject' => 'Hello', 'body' => 'Test', 'button' => 'Shop'], 'test_percent' => 20])->assertRedirect();
        $c = Campaign::sole();
        $this->actingAs($this->actor)->post($this->base().'/actions/campaign-transition', ['uuid' => $c->uuid, 'action' => 'start'])->assertRedirect();
        app(Engine::class)->run($this->store);
        $this->assertDatabaseCount('marketing_deliveries', 1);
        $this->assertSame('completed', $c->fresh()->status);
        $this->actingAs($this->actor)->post($this->base().'/actions/campaign-transition', ['uuid' => $c->uuid, 'action' => 'start'])->assertSessionHasErrors();
        $this->assertDatabaseCount('marketing_campaigns', 1);
    }

    public function test_resend_receipts_require_signature_deduplicate_and_do_not_downgrade_bounce(): void
    {
        config(['marketing.resend_webhook_secret' => 'whsec_'.base64_encode('webhook-test-secret')]);
        $c = $this->contact();
        $e = $this->enroll($c);
        app(Engine::class)->run($this->store);
        $d = Delivery::sole();
        $d->update(['status' => 'sent', 'provider_id' => 'mail-1', 'sent_at' => now()]);
        $send = function (string $type, string $id) {
            $body = json_encode(['type' => $type, 'created_at' => now()->toIso8601String(), 'data' => ['email_id' => 'mail-1']]);
            $ts = (string) now()->timestamp;
            $sig = base64_encode(hash_hmac('sha256', $id.'.'.$ts.'.'.$body, 'webhook-test-secret', true));

            return $this->call('POST', '/api/shopify-app/marketing/resend', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_SVIX_ID' => $id, 'HTTP_SVIX_TIMESTAMP' => $ts, 'HTTP_SVIX_SIGNATURE' => 'v1,'.$sig], $body);
        };
        $this->postJson('/api/shopify-app/marketing/resend', [])->assertUnauthorized();
        $send('email.bounced', 'event-1')->assertOk();
        $send('email.bounced', 'event-1')->assertOk();
        $send('email.delivered', 'event-2')->assertOk();
        $this->assertDatabaseCount('marketing_events', 2);
        $this->assertSame('bounced', $d->fresh()->status);
        $this->assertTrue($c->fresh()->suppressed);
    }

    public function test_uninstall_revokes_only_this_app_and_ignores_other_store(): void
    {
        config(['marketing.active.client_id' => 'marketing-client', 'marketing.active.client_secret' => 'marketing-secret']);
        $connection = ShopifyConnection::create(['store_id' => $this->store->id, 'shop_domain' => Guard::SHOP, 'status' => 'connected', 'access_token_encrypted' => 'other-token', 'api_version' => '2026-07', 'scopes' => ['read_orders']]);
        $app = App::create(['name' => 'Marketing', 'handle' => config('marketing.active.handle'), 'client_id' => 'marketing-client', 'status' => 'active']);
        $installation = AppInstallation::create(['app_id' => $app->id, 'store_id' => $this->store->id, 'shopify_connection_id' => $connection->id, 'status' => 'active', 'granted_scopes' => ['read_orders'], 'access_token_encrypted' => 'marketing-token', 'settings' => ['environment' => 'test']]);
        $body = '{}';
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, 'marketing-secret', true)), 'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'macfoxebike.myshopify.com', 'HTTP_X_SHOPIFY_TOPIC' => 'app/uninstalled', 'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'unique-event'];
        $this->call('POST', '/api/shopify-app/marketing/webhooks', [], [], [], $headers, $body)->assertForbidden();
        $this->assertSame('active', $installation->fresh()->status);
        $headers['HTTP_X_SHOPIFY_SHOP_DOMAIN'] = Guard::SHOP;
        $this->call('POST', '/api/shopify-app/marketing/webhooks', [], [], [], $headers, $body)->assertOk();
        $this->assertNull($installation->fresh()->access_token_encrypted);
        $this->assertSame('other-token', $connection->fresh()->access_token_encrypted);
        $this->assertFalse(Settings::forStore($this->store)->first()->enabled);
    }

    public function test_daily_limit_and_circuit_protect_real_send(): void
    {
        config(['marketing.transport' => 'resend', 'marketing.resend_key' => 'fake', 'marketing.from' => 'test@example.com']);
        Settings::forStore($this->store)->first()->update(['daily_limit' => 1, 'frequency_hours' => 0]);
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'mail-limit'], 200)]);
        $c = $this->contact();
        $e = $this->enroll($c);
        app(Engine::class)->run($this->store);
        $second = app(Engine::class)->enroll($this->store, $c, 'welcome', 'another-event');
        app(Engine::class)->run($this->store);
        $this->assertTrue($second->fresh()->next_at->isFuture());
        Http::assertSentCount(1);
        for ($i = 0; $i < 10; $i++) {
            Delivery::create(['organization_id' => $this->store->organization_id, 'store_id' => $this->store->id, 'contact_id' => $c->id, 'dedupe_key' => hash('sha256', 'failure'.$i), 'status' => 'failed', 'first_attempt_at' => now()]);
        }
        $this->assertTrue(app(Engine::class)->circuitOpen($this->store));
    }

    public function test_paused_campaign_does_not_send_and_cancel_keeps_history(): void
    {
        $c = $this->contact();
        $campaign = Campaign::create(['organization_id' => $this->store->organization_id, 'store_id' => $this->store->id, 'name' => 'Paused', 'status' => 'sending', 'scheduled_at' => now(), 'content' => ['subject' => 'Test', 'body' => 'Test', 'button' => 'Shop']]);
        app(Campaigns::class)->expand($this->store);
        $campaign->update(['status' => 'paused']);
        app(Engine::class)->run($this->store);
        $this->assertDatabaseCount('marketing_deliveries', 0);
        $this->actingAs($this->actor)->post($this->base().'/actions/campaign-transition', ['uuid' => $campaign->uuid, 'action' => 'cancel'])->assertRedirect();
        $this->assertSame('cancelled', $campaign->fresh()->status);
        $this->assertSame('stopped', Enrollment::sole()->status);
        $this->assertDatabaseCount('marketing_campaigns', 1);
    }

    public function test_wrong_tenant_record_is_not_mutated(): void
    {
        $c = $this->contact();
        $other = Organization::create(['name' => 'Other', 'code' => 'other']);
        $this->actingAs($this->actor)->post('/organizations/'.$other->id.'/stores/'.$this->store->id.'/marketing/actions/contact', ['uuid' => $c->uuid, 'action' => 'suppress'])->assertForbidden();
        $this->assertFalse($c->fresh()->suppressed);
    }

    public function test_stale_contact_instance_cannot_enroll_after_suppression(): void
    {
        $c = $this->contact();
        app(Contacts::class)->suppress($c);
        $this->assertNull(app(Engine::class)->enroll($this->store, $c, 'welcome', 'stale-contact'));
        $this->assertDatabaseCount('marketing_enrollments', 0);
    }

    public function test_stale_store_instance_cannot_bypass_persisted_boundary(): void
    {
        Store::whereKey($this->store->id)->update(['shopify_domain' => 'macfoxebike.myshopify.com']);
        $this->expectException(HttpException::class);
        app(Guard::class)->store($this->store);
    }

    public function test_contact_sync_continues_from_saved_cursor(): void
    {
        $calls = [];
        $this->partialMock(Shopify::class, function ($mock) use (&$calls) {
            $mock->shouldReceive('query')->andReturnUsing(function ($store, $query, $variables = []) use (&$calls) {
                if (str_contains($query, 'query MarketingCustomers')) {
                    $calls[] = $variables['after'];

                    return ['data' => ['customers' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => count($calls) === 1, 'endCursor' => 'next-page']]]];
                }
                $root = str_contains($query, 'query MarketingOrders') ? 'orders' : 'abandonedCheckouts';

                return ['data' => [$root => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]];
            });
        });
        app(Shopify::class)->sync($this->store);
        app(Shopify::class)->sync($this->store);
        $this->assertSame([null, 'next-page'], $calls);
        $this->assertNull(Settings::forStore($this->store)->first()->sync_state['contacts']['cursor']);
    }

    public function test_contact_export_requires_manage_and_escapes_spreadsheet_formulas(): void
    {
        $c = $this->contact();
        $c->update(['name_encrypted' => '=HYPERLINK("https://example.com")']);
        $response = $this->actingAs($this->actor)->get($this->base().'/export-contacts')->assertOk();
        $this->assertStringContainsString("'=HYPERLINK", $response->streamedContent());
        $this->assertDatabaseHas('audit_logs', ['store_id' => $this->store->id, 'action' => 'marketing.contacts.export']);
    }

    public function test_disabled_template_skips_only_its_step_and_preserves_history(): void
    {
        $e = $this->enroll();
        $template = Template::forStore($this->store)->where('key', 'welcome_1')->firstOrFail();
        app(Management::class)->change($this->store, $this->actor, 'template-toggle', ['uuid' => $template->uuid, 'enabled' => false]);
        app(Engine::class)->run($this->store);
        $this->assertSame('skipped', Delivery::sole()->status);
        $this->assertSame('template_disabled', Delivery::sole()->reason);
        $this->assertSame(1, $e->fresh()->step);
        app(Management::class)->change($this->store, $this->actor, 'template-toggle', ['uuid' => $template->uuid, 'enabled' => true]);
        app(Engine::class)->run($this->store);
        $this->assertDatabaseCount('marketing_deliveries', 1);
        Http::assertNothingSent();
    }

    public function test_smart_sending_skips_campaign_instead_of_delaying_it(): void
    {
        $contact = $this->contact();
        $contact->update(['last_sent_at' => now()->subHours(12)]);
        $campaign = Campaign::create(['organization_id' => $this->store->organization_id, 'store_id' => $this->store->id, 'name' => 'Smart sending', 'status' => 'sending', 'scheduled_at' => now(), 'content' => ['subject' => 'Test', 'body' => 'Test', 'button' => 'Shop']]);
        app(Engine::class)->run($this->store);
        $e = Enrollment::forStore($this->store)->where('campaign_id', $campaign->id)->sole();
        $this->assertSame('stopped', $e->status);
        $this->assertSame('smart_sending', $e->stop_reason);
        $this->assertNull($e->next_at);
        $this->assertDatabaseCount('marketing_deliveries', 0);
        Http::assertNothingSent();
    }

    public function test_campaign_test_sends_selected_variant_once_without_starting_campaign(): void
    {
        config(['marketing.transport' => 'resend', 'marketing.resend_key' => 'fake', 'marketing.from' => 'test@example.com']);
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'campaign-test'], 200)]);
        $campaign = Campaign::create(['organization_id' => $this->store->organization_id, 'store_id' => $this->store->id, 'name' => 'Test campaign', 'content' => ['subject' => 'A subject', 'body' => 'A body', 'button' => 'Shop'], 'variant_b' => ['subject' => 'B subject', 'body' => 'B body', 'button' => 'Shop']]);
        $input = ['uuid' => $campaign->uuid, 'recipient' => 'jiushizheyike@gmail.com', 'variant' => 'B', 'request_id' => (string) Str::uuid()];
        app(Management::class)->change($this->store, $this->actor, 'campaign-test', $input);
        app(Management::class)->change($this->store, $this->actor, 'campaign-test', $input);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['subject'] === 'B subject');
        $this->assertSame('draft', $campaign->fresh()->status);
        $this->assertSame('campaign_test', Delivery::sole()->kind);
        $this->assertDatabaseCount('marketing_enrollments', 0);
    }

    public function test_overview_uses_thirty_days_excludes_test_mail_and_keeps_currencies_separate(): void
    {
        $e = $this->enroll();
        foreach ([['new', 'automation', now(), 'USD'], ['eur', 'automation', now(), 'EUR'], ['old', 'automation', now()->subDays(31), null], ['test', 'template_test', now(), 'USD']] as [$key, $kind, $at, $currency]) {
            $d = Delivery::create(['organization_id' => $this->store->organization_id, 'store_id' => $this->store->id, 'contact_id' => $e->contact_id, 'enrollment_id' => $kind === 'automation' ? $e->id : null, 'kind' => $kind, 'status' => 'sent', 'dedupe_key' => hash('sha256', $key), 'sent_at' => $at, 'opened_at' => $at, 'clicked_at' => $at]);
            if ($currency) {
                Attribution::create(['organization_id' => $this->store->organization_id, 'store_id' => $this->store->id, 'delivery_id' => $d->id, 'order_id' => $key, 'revenue' => 100, 'currency' => $currency, 'status' => 'paid', 'ordered_at' => now()]);
            }
        }
        $report = app(Reports::class)->overview($this->store);
        $this->assertSame(2, $report['sent']);
        $this->assertSame(100.0, $report['raw_open_rate']);
        $this->assertNull($report['human_open_rate']);
        $this->assertCount(2, $report['revenue']);
        $this->assertSame(2, $report['attributed_orders']);
        $this->assertSame(2, collect($report['flows'])->firstWhere('key', 'welcome')['sent']);
        $this->assertCount(2, $report['revenue_by_flow']);
    }

    public function test_advocacy_audience_excludes_clicked_unsubscribed_and_test_only_contacts(): void
    {
        $eligible = $this->contact();
        $clicked = $this->contact('clicked@example.invalid');
        $testOnly = $this->contact('test-only@example.invalid');
        foreach ([$eligible, $clicked, $testOnly] as $contact) {
            $e = Enrollment::create(['organization_id' => $this->store->organization_id, 'store_id' => $this->store->id, 'contact_id' => $contact->id, 'flow_key' => 'advocacy', 'source_key' => 'audience-'.$contact->id, 'steps' => []]);
            Delivery::create(['organization_id' => $this->store->organization_id, 'store_id' => $this->store->id, 'contact_id' => $contact->id, 'enrollment_id' => $e->id, 'kind' => $contact->is($testOnly) ? 'template_test' : 'automation', 'dedupe_key' => hash('sha256', 'audience-'.$contact->id), 'sent_at' => now(), 'opened_at' => now(), 'clicked_at' => $contact->is($clicked) ? now() : null]);
        }
        $audiences = app(Audiences::class);
        $this->assertSame([$eligible->id], $audiences->query($this->store, 'advocacy_opened_not_clicked')->pluck('id')->all());
        app(Contacts::class)->unsubscribe($eligible);
        $this->assertSame(0, $audiences->query($this->store, 'advocacy_opened_not_clicked')->count());
    }

    public function test_template_reset_preserves_enrolled_content_and_does_not_enable_template(): void
    {
        $e = $this->enroll();
        $snapshot = $e->steps;
        $template = Template::forStore($this->store)->where('key', 'welcome_1')->firstOrFail();
        $template->update(['enabled' => false, 'draft' => ['subject' => 'Changed', 'body' => 'Changed', 'button' => 'Changed']]);
        app(Management::class)->change($this->store, $this->actor, 'template-reset', ['uuid' => $template->uuid]);
        $this->assertSame('Welcome to {storeName}', $template->fresh()->published['subject']);
        $this->assertFalse($template->fresh()->enabled);
        $this->assertSame($snapshot, $e->fresh()->steps);
        $this->assertNull($template->fresh()->tested_at);
    }

    public function test_checkout_migration_resumes_missing_index_without_losing_records(): void
    {
        $row = CheckoutSnapshot::create(['organization_id' => $this->store->organization_id, 'store_id' => $this->store->id, 'checkout_id' => 'retained-checkout', 'occurred_at' => now()]);
        Schema::table('marketing_checkout_snapshots', fn ($table) => $table->dropIndex('marketing_checkout_scope_date'));
        $migration = require base_path('shopify-apps/deco-marketing/database/migrations/2026_09_10_020000_create_marketing_checkout_snapshots.php');
        $migration->up();
        $migration->up();
        $this->assertSame('retained-checkout', $row->fresh()->checkout_id);
        $this->assertTrue(Schema::hasIndex('marketing_checkout_snapshots', 'marketing_checkout_scope_date'));
        $this->assertDatabaseCount('marketing_checkout_snapshots', 1);
    }

    public function test_popup_funnel_separates_all_time_subscribers_and_unique_daily_actions(): void
    {
        $this->travelTo(Carbon::parse('2026-09-09T12:00:00Z'));
        $old = $this->contact('old-popup@example.invalid');
        $old->update(['source' => 'storefront', 'created_at' => now()->subDays(40)]);
        $new = $this->contact('new-popup@example.invalid');
        $new->update(['source' => 'storefront_popup']);
        foreach (['a' => ['impression', 'submit', 'close'], 'b' => ['impression', 'close'], 'c' => ['impression']] as $visitor => $types) {
            foreach ($types as $type) {
                PopupEvent::firstOrCreate(['organization_id' => $this->store->organization_id, 'store_id' => $this->store->id, 'visitor_hash' => hash('sha256', $visitor), 'day' => '2026-09-09', 'type' => $type]);
            }
        }
        PopupEvent::create(['organization_id' => $this->store->organization_id, 'store_id' => $this->store->id, 'visitor_hash' => hash('sha256', 'old'), 'day' => '2026-07-31', 'type' => 'impression']);
        $report = app(PopupAnalytics::class)->report($this->store, 30);
        $this->assertSame('2026-08-11', $report['start']);
        $this->assertSame(2, $report['subscribers']);
        $this->assertSame(1, $report['new_subscribers']);
        $this->assertSame(3, $report['impressions']);
        $this->assertSame(1, $report['submissions']);
        $this->assertSame(2, $report['closes']);
        $this->assertSame(1, $report['inactive']);
        $this->assertSame(33.33, $report['inactive_rate']);
        $this->assertCount(1, $report['daily']);
        $long = app(PopupAnalytics::class)->report($this->store, 90);
        $this->assertSame(2, $long['subscribers']);
        $this->assertSame(2, $long['new_subscribers']);
        $this->assertSame(4, $long['impressions']);
    }

    public function test_popup_submit_event_is_counted_once_even_when_subscription_is_rejected(): void
    {
        Settings::forStore($this->store)->first()->update(['popup' => ['enabled' => true, 'heading' => 'Test', 'body' => 'Test']]);
        $visitor = (string) Str::uuid();
        $event = Request::create('/popup/event', 'POST', ['visitor' => $visitor, 'type' => 'submit']);
        $event->attributes->set('marketing_store', $this->store);
        $controller = app(PublicController::class);
        $controller->event($event);
        $controller->event($event);
        $invalid = Request::create('/popup/subscribe', 'POST', ['visitor' => $visitor, 'email' => 'invalid', 'consent' => true]);
        $invalid->attributes->set('marketing_store', $this->store);
        try {
            $controller->subscribe($invalid);
            $this->fail('Invalid email must be rejected.');
        } catch (ValidationException) {
        }
        $this->assertDatabaseCount('marketing_popup_events', 1);
        $this->assertDatabaseCount('marketing_contacts', 0);
        $this->assertSame(1, app(PopupAnalytics::class)->report($this->store)['submissions']);
    }
}
