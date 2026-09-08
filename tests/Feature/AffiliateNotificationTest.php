<?php

namespace Tests\Feature;

use App\Domain\ReferralAffiliate\Models\AffiliateProgram;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Models\AffiliatePromoter;
use App\Domain\ReferralAffiliate\Services\AffiliateNotificationService;
use App\Jobs\SendAffiliateNotification;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AffiliateNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_intents_are_encrypted_deduplicated_and_off_until_template_enabled(): void
    {
        Queue::fake();
        $member = $this->member();
        $svc = app(AffiliateNotificationService::class);
        $first = $svc->intent($member, 'conversion.created', 'order:1');
        $again = $svc->intent($member, 'conversion.created', 'order:1');
        $this->assertSame($first->id, $again->id);
        $this->assertSame('suppressed', $first->status);
        $this->assertStringNotContainsString('mail@example.invalid', $first->getRawOriginal('message_encrypted'));
        Queue::assertNothingPushed();
        DB::table('affiliate_message_templates')->insert(['organization_id' => $member->organization_id, 'store_id' => $member->store_id, 'key' => 'conversion.created', 'subject' => 'Hello {name}', 'body' => 'Program {program}', 'enabled' => true, 'version' => 3, 'created_at' => now(), 'updated_at' => now()]);
        $next = $svc->intent($member, 'conversion.created', 'order:2');
        $this->assertSame('queued', $next->status);
        $this->assertSame(3, $next->template_version);
        $this->assertSame('Hello Test', $next->message_encrypted['subject']);
        Queue::assertPushed(SendAffiliateNotification::class, 1);
    }

    public function test_sending_twice_uses_one_delivery_and_never_logs_email_body(): void
    {
        Queue::fake();
        $member = $this->member();
        $svc = app(AffiliateNotificationService::class);
        $intent = $svc->intent($member, 'conversion.created', 'order:1');
        $intent->update(['status' => 'queued']);
        config(['mail.default' => 'smtp']);
        Mail::shouldReceive('raw')->once()->withArgs(fn ($body, $callback) => is_string($body) && is_callable($callback))->andReturn(null);
        $svc->send($intent->id);
        $svc->send($intent->id);
        $this->assertSame('sent', $intent->fresh()->status);
        $this->assertSame(1, $intent->fresh()->attempts);
    }

    public function test_expired_login_intent_is_suppressed_without_delivery(): void
    {
        Queue::fake();
        Mail::shouldReceive('raw')->never();
        $member = $this->member();
        $service = app(AffiliateNotificationService::class);
        $intent = $service->intent($member, 'conversion.created', 'login:expired');
        $intent->update(['event_key' => 'portal.login', 'status' => 'queued']);
        $this->travel(16)->minutes();
        $service->send($intent->id);
        $this->assertSame('suppressed', $intent->fresh()->status);
        $this->assertSame([], $intent->fresh()->message_encrypted);
    }

    private function member(): AffiliateProgramMembership
    {
        $org = Organization::query()->create(['name' => 'Mail test', 'code' => 'mail-test']);
        $store = $org->stores()->create(['name' => 'Test', 'shopify_domain' => 'macfox-test-app.myshopify.com', 'currency' => 'USD', 'status' => 'active']);
        $program = AffiliateProgram::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'name' => 'Creators', 'type' => 'affiliate', 'currency' => 'USD']);
        $promoter = AffiliatePromoter::query()->create(['organization_id' => $org->id, 'display_name' => 'Test', 'email_encrypted' => 'mail@example.invalid', 'email_hash' => str_repeat('c', 64), 'type' => 'affiliate', 'status' => 'active']);

        return AffiliateProgramMembership::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'program_id' => $program->id, 'promoter_id' => $promoter->id, 'status' => 'approved']);
    }
}
