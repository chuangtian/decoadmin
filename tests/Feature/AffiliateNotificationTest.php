<?php

namespace Tests\Feature;

use App\Domain\ReferralAffiliate\Models\AffiliateProgram;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Models\AffiliatePromoter;
use App\Domain\ReferralAffiliate\Models\AffiliateStoreSetting;
use App\Domain\ReferralAffiliate\Services\AffiliateInvitationOrderReader;
use App\Domain\ReferralAffiliate\Services\AffiliateNotificationService;
use App\Jobs\SendAffiliateNotification;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
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
        DB::table('affiliate_message_templates')->insert(['organization_id' => $member->organization_id, 'store_id' => $member->store_id, 'key' => 'conversion.created', 'subject' => 'Notice', 'body' => 'Test notice', 'enabled' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $intent = $svc->intent($member, 'conversion.created', 'order:1');
        $this->assertSame('queued', $intent->status);
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

    public static function businessNotificationCases(): array
    {
        return array_map(fn ($key) => [$key], array_values(array_diff(array_keys(AffiliateNotificationService::DEFAULTS), ['customer.invited'])));
    }

    #[DataProvider('businessNotificationCases')]
    public function test_disabling_template_stops_queued_business_notification_and_retry(string $event): void
    {
        Queue::fake();
        config(['mail.default' => 'array']);
        $member = $this->member();
        DB::table('affiliate_message_templates')->insert(['organization_id' => $member->organization_id, 'store_id' => $member->store_id, 'key' => $event, 'subject' => 'Notice', 'body' => 'Test notice', 'enabled' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $service = app(AffiliateNotificationService::class);
        $queued = $service->intent($member, $event, 'queued:'.$event);
        $failed = $service->intent($member, $event, 'failed:'.$event);
        $failed->update(['status' => 'failed']);
        DB::table('affiliate_message_templates')->where('key', $event)->update(['enabled' => false]);
        foreach ([$queued, $failed] as $intent) {
            $service->send($intent->id);
            $this->assertSame('suppressed', $intent->fresh()->status);
            $this->assertSame([], $intent->fresh()->message_encrypted);
        }
        DB::table('affiliate_message_templates')->where('key', $event)->update(['enabled' => true]);
        $service->send($queued->id);
        $service->send($failed->id);
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
        $new = $service->intent($member, $event, 'new:'.$event);
        $service->send($new->id);
        $this->assertSame('sent', $new->fresh()->status);
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_fresh_portal_login_is_independent_of_business_templates(): void
    {
        Queue::fake();
        config(['mail.default' => 'array']);
        $member = $this->member();
        $service = app(AffiliateNotificationService::class);
        $intent = $service->intent($member, 'conversion.created', 'login:fresh');
        $intent->update(['event_key' => 'portal.login', 'status' => 'queued']);
        $service->send($intent->id);
        $this->assertSame('sent', $intent->fresh()->status);
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public static function stoppedInvitationCases(): array
    {
        return array_map(fn ($case) => [$case], ['template', 'store_setting', 'program_paused', 'auto_invite', 'member_rejected', 'promoter_disabled']);
    }

    #[DataProvider('stoppedInvitationCases')]
    public function test_queued_invitation_is_suppressed_when_its_sending_conditions_change(string $case): void
    {
        Queue::fake();
        config(['mail.default' => 'array']);
        $member = $this->member();
        $member->forceFill(['status' => 'pending', 'application_encrypted' => ['source_order_id' => 'gid://shopify/Order/80']])->save();
        $member->program->update(['type' => 'advocate', 'status' => 'active', 'settings' => ['auto_invite' => true]]);
        $setting = AffiliateStoreSetting::query()->create(['organization_id' => $member->organization_id, 'store_id' => $member->store_id, 'customer_referral_enabled' => true]);
        DB::table('affiliate_message_templates')->insert(['organization_id' => $member->organization_id, 'store_id' => $member->store_id, 'key' => 'customer.invited', 'subject' => 'Invite', 'body' => '{invitation_url}', 'enabled' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $service = app(AffiliateNotificationService::class);
        $intent = $service->intent($member, 'customer.invited', 'queued-invite', ['invitation_url' => url('/referral-portal/invitation').'#token='.str_repeat('a', 64)]);
        $this->assertSame('queued', $intent->status);
        match ($case) {
            'template' => DB::table('affiliate_message_templates')->where('key', 'customer.invited')->update(['enabled' => false]),
            'store_setting' => $setting->update(['customer_referral_enabled' => false]),
            'program_paused' => $member->program->update(['status' => 'paused']),
            'auto_invite' => $member->program->update(['settings' => ['auto_invite' => false]]),
            'member_rejected' => $member->update(['status' => 'rejected']),
            'promoter_disabled' => $member->promoter->update(['status' => 'disabled']),
        };
        $this->mock(AffiliateInvitationOrderReader::class)->shouldReceive('eligibleContact')->zeroOrMoreTimes()->andReturn(['email' => 'mail@example.invalid']);
        $service->send($intent->id);
        $service->send($intent->id);
        $this->assertSame('suppressed', $intent->fresh()->status);
        $this->assertSame([], $intent->fresh()->message_encrypted);
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
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
