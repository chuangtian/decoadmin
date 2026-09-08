<?php

namespace Tests\Feature;

use App\Domain\ReferralAffiliate\Models\AffiliateLedgerEntry;
use App\Domain\ReferralAffiliate\Models\AffiliateProgram;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Models\AffiliatePromoter;
use App\Domain\ReferralAffiliate\Services\AffiliatePayoutService;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AffiliatePayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_cancellation_and_paid_confirmation_are_idempotent(): void
    {
        [$org,$store,$actor,$member] = $this->context();
        $this->entry($member, 10000, 'a');
        $svc = app(AffiliatePayoutService::class);
        $batch = $svc->create($org, $store, $actor, 'USD', 5000, CarbonImmutable::now());
        $this->assertSame(10000, $batch->total_minor);
        $this->assertSame('reserved', AffiliateLedgerEntry::query()->sole()->status);
        try {
            $svc->create($org, $store, $actor, 'USD', 5000, CarbonImmutable::now());
            $this->fail('Double reservation');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $svc->transition($org, $store, $actor, $batch->public_id, 'cancelled');
        $this->assertSame('available', AffiliateLedgerEntry::query()->sole()->status);
        $next = $svc->create($org, $store, $actor, 'USD', 5000, CarbonImmutable::now());
        $svc->transition($org, $store, $actor, $next->public_id, 'paid', 'TEST-NO-TRANSFER');
        $svc->transition($org, $store, $actor, $next->public_id, 'paid', 'TEST-NO-TRANSFER');
        $this->assertDatabaseCount('affiliate_ledger_entries', 2);
        $this->assertSame(0, (int) AffiliateLedgerEntry::query()->sum('amount_minor'));
        $this->assertSame('paid', $next->fresh()->status);
    }

    public function test_negative_balance_across_programs_offsets_next_payment(): void
    {
        [$org,$store,$actor,$member] = $this->context();
        $otherProgram = AffiliateProgram::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'name' => 'Second', 'type' => 'affiliate', 'currency' => 'USD']);
        $other = AffiliateProgramMembership::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'program_id' => $otherProgram->id, 'promoter_id' => $member->promoter_id, 'status' => 'approved']);
        $this->entry($member, -3000, 'refund');
        $this->entry($other, 10000, 'next');
        $batch = app(AffiliatePayoutService::class)->create($org, $store, $actor, 'USD', 5000, CarbonImmutable::now());
        $this->assertSame(7000, $batch->total_minor);
        $this->assertSame(2, $batch->items()->count());
    }

    public function test_refund_after_reservation_blocks_payment_until_batch_is_rebuilt(): void
    {
        [$org,$store,$actor,$member] = $this->context();
        $this->entry($member, 10000, 'earned');
        $svc = app(AffiliatePayoutService::class);
        $batch = $svc->create($org, $store, $actor, 'USD', 1, CarbonImmutable::now());
        $this->entry($member, -3000, 'late-refund');
        try {
            $svc->transition($org, $store, $actor, $batch->public_id, 'paid', 'TEST-NO-TRANSFER');
            $this->fail('Stale payout allowed');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame('draft', $batch->fresh()->status);
        $this->assertDatabaseMissing('affiliate_ledger_entries', ['type' => 'payout_settlement']);
        $svc->transition($org, $store, $actor, $batch->public_id, 'cancelled');
        $next = $svc->create($org, $store, $actor, 'USD', 1, CarbonImmutable::now());
        $this->assertSame(7000, $next->total_minor);
        $svc->transition($org, $store, $actor, $next->public_id, 'paid', 'TEST-NO-TRANSFER');
        $this->assertSame(0, (int) AffiliateLedgerEntry::query()->sum('amount_minor'));
    }

    public function test_viewer_cannot_reserve_or_confirm_payment(): void
    {
        [$org,$store,$actor,$member] = $this->context('viewer');
        $this->entry($member, 10000, 'a');
        $this->expectException(HttpException::class);
        app(AffiliatePayoutService::class)->create($org, $store, $actor, 'USD', 5000, CarbonImmutable::now());
    }

    private function entry($member, int $amount, string $key): void
    {
        AffiliateLedgerEntry::query()->create(['organization_id' => $member->organization_id, 'store_id' => $member->store_id, 'membership_id' => $member->id, 'idempotency_key' => $key, 'type' => 'manual_adjustment', 'status' => 'available', 'currency' => 'USD', 'amount_minor' => $amount]);
    }

    private function context(string $role = 'store-admin'): array
    {
        $this->seed(PermissionSeeder::class);
        $org = Organization::query()->create(['name' => 'Payout', 'code' => 'payout']);
        $actor = User::factory()->create(['email_verified_at' => now()]);
        $org->users()->attach($actor, ['status' => 'active', 'joined_at' => now()]);
        $store = $org->stores()->create(['name' => 'Test', 'shopify_domain' => 'macfox-test-app.myshopify.com', 'currency' => 'USD', 'status' => 'active']);
        $store->members()->attach($actor, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $r = Role::query()->where('organization_id', $org->id)->where('slug', $role)->firstOrFail();
        $actor->roles()->attach($r, ['organization_id' => $org->id, 'store_id' => null]);
        $program = AffiliateProgram::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'name' => 'Test', 'type' => 'affiliate', 'currency' => 'USD']);
        $promoter = AffiliatePromoter::query()->create(['organization_id' => $org->id, 'email_encrypted' => 'test@example.invalid', 'email_hash' => str_repeat('a', 64), 'display_name' => 'Test', 'type' => 'affiliate', 'status' => 'active']);
        $member = AffiliateProgramMembership::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'program_id' => $program->id, 'promoter_id' => $promoter->id, 'status' => 'approved']);

        return [$org, $store, $actor, $member];
    }
}
