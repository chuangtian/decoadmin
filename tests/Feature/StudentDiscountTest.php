<?php

namespace Tests\Feature;

use App\Exceptions\StudentDiscountException;
use App\Jobs\SendStudentDiscountDecisionMail;
use App\Mail\StudentDiscountDecisionMail;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Role;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\StudentDiscountCampaign;
use App\Models\StudentDiscountClaim;
use App\Models\StudentDiscountCode;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\StudentDiscount\StudentDiscountClaimService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StudentDiscountTest extends TestCase
{
    use RefreshDatabase;

    public function test_gemini_key_is_encrypted_and_never_returned_to_super_admin(): void
    {
        [$user, $organization, $store] = $this->context('super-admin');
        $this->setting('student_ai', 'gemini_api_key', 'gemini-top-secret', true, $user);
        $this->setting('student_ai', 'gemini_model', 'gemini-2.5-pro', false, $user);
        $this->setting('student_ai', 'auto_approval_threshold', 80, false, $user);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('system.settings.index'))
            ->assertOk()
            ->assertDontSee('gemini-top-secret')
            ->assertInertia(fn (Assert $page) => $page
                ->where('canUpdateAi', true)
                ->where('aiSettings.gemini_api_key', '')
                ->where('aiSettings.gemini_api_key_configured', true)
                ->where('aiSettings.gemini_model', 'gemini-2.5-pro')
                ->where('aiSettings.auto_approval_threshold', 80));

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->put(route('system.settings.student-ai.update'), [
                'gemini_api_key' => '',
                'gemini_model' => 'gemini-2.5-pro',
                'auto_approval_threshold' => 82,
            ])
            ->assertRedirect();

        $this->assertSame('gemini-top-secret', $this->storedValue('student_ai', 'gemini_api_key'));
        $this->assertSame(82, $this->storedValue('student_ai', 'auto_approval_threshold'));
        $raw = DB::table('system_settings')->where('section', 'student_ai')->where('key', 'gemini_api_key')->value('value');
        $this->assertStringNotContainsString('gemini-top-secret', (string) $raw);
        $this->assertStringNotContainsString('gemini-top-secret', AuditLog::query()->get()->toJson());
    }

    public function test_non_super_admin_does_not_receive_ai_settings_card_or_update_it(): void
    {
        [$user, $organization, $store] = $this->context('viewer');

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('system.settings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canUpdateAi', false)
                ->where('aiSettings', null));

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->put(route('system.settings.student-ai.update'), [
                'gemini_api_key' => 'forbidden-secret',
                'gemini_model' => 'gemini-2.5-pro',
                'auto_approval_threshold' => 80,
            ])
            ->assertForbidden();
    }

    public function test_signed_education_email_claim_creates_and_reuses_shopify_discount_idempotently(): void
    {
        Mail::fake();
        Queue::fake();
        [$user, $organization, $store] = $this->context('store-admin');
        $this->campaign($organization, $store, ['enabled' => true]);
        $connection = $this->connection($store);
        config(['student_discount.active.client_secret' => 'proxy-shared-secret']);
        $this->studentInstallation($store, $connection);
        Http::fakeSequence('https://student-test.myshopify.com/*')
            ->push(['data' => ['codeDiscountNodeByCode' => null]])
            ->push(['data' => ['discountCodeBasicCreate' => [
                'codeDiscountNode' => ['id' => 'gid://shopify/DiscountCodeNode/123'],
                'userErrors' => [],
            ]]]);

        $url = $this->signedProxyUrl(route('student-discounts.public.claims.store'), 'student-test.myshopify.com');
        $first = $this->postJson($url, ['email' => 'student@school.edu', 'idempotency_key' => 'claim-fast-pass-001'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.discount.status', 'unused')
            ->assertJsonPath('data.discount.usage_limit', 1);

        $code = StudentDiscountCode::query()->sole();
        $this->assertSame('gid://shopify/DiscountCodeNode/123', $code->shopify_discount_id);
        $this->assertEquals(7, $code->generated_at->diffInDays($code->expires_at));
        Mail::assertNothingSent();

        $secondUrl = $this->signedProxyUrl(route('student-discounts.public.claims.store'), 'student-test.myshopify.com');
        $this->postJson($secondUrl, ['email' => 'student@school.edu', 'idempotency_key' => 'claim-fast-pass-001'])
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.discount.code', $first->json('data.discount.code'));

        $this->assertDatabaseCount('student_discount_claims', 1);
        $this->assertDatabaseCount('student_discount_codes', 1);
        Queue::assertPushed(SendStudentDiscountDecisionMail::class, function (SendStudentDiscountDecisionMail $job) use ($code): bool {
            return $job->organizationId === $code->organization_id
                && $job->storeId === $code->store_id
                && $job->claimId === $code->claim_id
                && $job->codeId === $code->id;
        });
        Queue::assertPushed(SendStudentDiscountDecisionMail::class, 1);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->header('X-Shopify-Access-Token')[0] === 'student-app-offline-token');
        Http::assertNotSent(fn ($request): bool => $request->header('X-Shopify-Access-Token')[0] === 'shopify-test-token');
        Http::assertSent(function ($request): bool {
            if (! str_contains((string) ($request['query'] ?? ''), 'discountCodeBasicCreate')) {
                return false;
            }

            $input = data_get($request['variables'] ?? [], 'basicCodeDiscount', []);

            return data_get($input, 'context.all') === 'ALL'
                && data_get($input, 'customerGets.items.all') === true
                && is_bool(data_get($input, 'customerGets.items.all'));
        });
    }

    public function test_education_email_claim_requires_current_store_student_app_token(): void
    {
        Mail::fake();
        [, $organization, $store] = $this->context('store-admin');
        $this->campaign($organization, $store, ['enabled' => true]);
        $this->connection($store);
        config(['student_discount.active.client_secret' => 'proxy-shared-secret']);
        $otherStore = $organization->stores()->create([
            'name' => 'Other Student Store',
            'shopify_domain' => 'other-student.myshopify.com',
            'status' => 'active',
        ]);
        $this->studentInstallation($otherStore, $this->connection($otherStore));
        Http::fake();

        $payload = ['email' => 'student@school.edu', 'idempotency_key' => 'claim-current-store-token-001'];
        $this->postJson($this->signedProxyUrl(route('student-discounts.public.claims.store'), $store->shopify_domain), $payload)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STUDENT_DISCOUNT_APP_REAUTH_REQUIRED');

        $this->assertDatabaseHas('student_discount_claims', ['store_id' => $store->id, 'status' => 'pending']);
        $this->assertDatabaseCount('student_discount_claim_idempotencies', 1);
        Http::assertNothingSent();
    }

    public function test_expired_student_app_token_is_refreshed_and_rotated_before_issuing_code(): void
    {
        Mail::fake();
        Queue::fake();
        [, $organization, $store] = $this->context('store-admin');
        $this->campaign($organization, $store, ['enabled' => true]);
        $connection = $this->connection($store);
        config(['student_discount.active.client_secret' => 'proxy-shared-secret']);
        $installation = $this->studentInstallation($store, $connection, [
            'access_token_encrypted' => 'expired-student-app-token',
            'refresh_token_encrypted' => 'student-app-refresh-token-old',
            'access_token_expires_at' => now()->subMinute(),
        ]);
        Http::fakeSequence("https://{$store->shopify_domain}/*")
            ->push([
                'access_token' => 'student-app-offline-token-new',
                'refresh_token' => 'student-app-refresh-token-new',
                'expires_in' => 3600,
                'refresh_token_expires_in' => 7776000,
                'scope' => 'read_discounts,write_discounts,read_products,write_app_proxy',
            ])
            ->push(['data' => ['codeDiscountNodeByCode' => null]])
            ->push(['data' => ['discountCodeBasicCreate' => [
                'codeDiscountNode' => ['id' => 'gid://shopify/DiscountCodeNode/refreshed'],
                'userErrors' => [],
            ]]]);

        $this->postJson($this->signedProxyUrl(route('student-discounts.public.claims.store'), $store->shopify_domain), [
            'email' => 'student@school.edu',
            'idempotency_key' => 'claim-token-refresh-001',
        ])->assertOk()->assertJsonPath('data.status', 'approved');

        Http::assertSent(fn ($request): bool => $request->url() === "https://{$store->shopify_domain}/admin/oauth/access_token"
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'student-app-refresh-token-old');
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/graphql.json')
            && $request->header('X-Shopify-Access-Token')[0] === 'student-app-offline-token-new');
        $this->assertSame('student-app-offline-token-new', $installation->fresh()->access_token_encrypted);
        $this->assertSame('student-app-refresh-token-new', $installation->fresh()->refresh_token_encrypted);
        $this->assertStringNotContainsString(
            'student-app-offline-token-new',
            (string) DB::table('app_installations')->where('id', $installation->id)->value('access_token_encrypted'),
        );
    }

    public function test_low_confidence_student_id_is_queued_for_manual_review_without_auto_rejection(): void
    {
        Storage::fake('local');
        Mail::fake();
        [, $organization, $store] = $this->context('store-admin');
        $this->campaign($organization, $store, ['enabled' => true]);
        $this->setting('student_ai', 'gemini_api_key', 'test-gemini-key', true, null);
        $this->setting('student_ai', 'gemini_model', 'gemini-2.5-pro', false, null);
        $this->setting('student_ai', 'auto_approval_threshold', 80, false, null);
        config(['student_discount.active.client_secret' => 'proxy-shared-secret']);
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'is_student_id' => true,
                    'institution_name' => 'Example University',
                    'confidence' => 79,
                    'review_notes' => 'Needs manual review',
                ], JSON_THROW_ON_ERROR)]]]]],
            ]),
        ]);

        $url = $this->signedProxyUrl(route('student-discounts.public.claims.store'), 'student-test.myshopify.com');
        $response = $this->post($url, [
            'email' => 'student@example.com',
            'idempotency_key' => 'claim-evidence-001',
            'evidence' => UploadedFile::fake()->create('student-id.webp', 128, 'image/webp'),
        ], ['Accept' => 'application/json']);

        $response->assertAccepted()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.discount', null);
        $claim = StudentDiscountClaim::query()->sole();
        $this->assertSame('pending', $claim->status);
        $this->assertSame('79.00', $claim->confidence);
        $this->assertSame('ai', $claim->review_method);
        $this->assertSame('Example University', data_get($claim->recognition_result, 'institution_name'));
        Storage::disk('local')->assertExists($claim->evidence_path);
        Mail::assertNothingSent();
        $this->assertDatabaseMissing('student_discount_codes', ['store_id' => $store->id]);
    }

    public function test_high_confidence_non_student_id_is_never_auto_approved(): void
    {
        Storage::fake('local');
        Mail::fake();
        [, $organization, $store] = $this->context('store-admin');
        $this->campaign($organization, $store, ['enabled' => true]);
        $this->setting('student_ai', 'gemini_api_key', 'test-gemini-key', true, null);
        $this->setting('student_ai', 'gemini_model', 'gemini-2.5-pro', false, null);
        $this->setting('student_ai', 'auto_approval_threshold', 80, false, null);
        config(['student_discount.active.client_secret' => 'proxy-shared-secret']);
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'is_student_id' => false,
                    'institution_name' => null,
                    'confidence' => 99,
                    'review_notes' => 'Not a student ID',
                ], JSON_THROW_ON_ERROR)]]]]],
            ]),
        ]);

        $url = $this->signedProxyUrl(route('student-discounts.public.claims.store'), 'student-test.myshopify.com');
        $this->post($url, [
            'email' => 'student@example.com',
            'idempotency_key' => 'claim-not-student-001',
            'evidence' => UploadedFile::fake()->create('not-a-student-id.webp', 128, 'image/webp'),
        ], ['Accept' => 'application/json'])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.discount', null);

        $claim = StudentDiscountClaim::query()->sole();
        $this->assertSame('pending', $claim->status);
        $this->assertFalse(data_get($claim->recognition_result, 'is_student_id'));
        $this->assertDatabaseMissing('student_discount_codes', ['store_id' => $store->id]);
        Mail::assertNothingSent();
    }

    public function test_idempotency_rejects_different_evidence_with_the_same_size_and_mime(): void
    {
        Storage::fake('local');
        [, $organization, $store] = $this->context('store-admin');
        $this->campaign($organization, $store, ['enabled' => true]);
        $claims = app(StudentDiscountClaimService::class);

        $claims->submit(
            $store,
            'student@example.com',
            UploadedFile::fake()->createWithContent('first.webp', str_repeat('A', 128)),
            'claim-content-hash-001',
        );

        try {
            $claims->submit(
                $store,
                'student@example.com',
                UploadedFile::fake()->createWithContent('second.webp', str_repeat('B', 128)),
                'claim-content-hash-001',
            );
            $this->fail('Different evidence content must not reuse the same idempotency result.');
        } catch (StudentDiscountException $exception) {
            $this->assertSame('IDEMPOTENCY_CONFLICT', $exception->errorCode);
            $this->assertSame(409, $exception->statusCode);
        }

        $this->assertDatabaseCount('student_discount_claims', 1);
        $this->assertDatabaseCount('student_discount_claim_idempotencies', 1);
    }

    public function test_invalid_signature_is_rejected_and_reviewed_evidence_is_pruned_after_thirty_days(): void
    {
        Storage::fake('local');
        [, $organization, $store] = $this->context('store-admin');
        config(['student_discount.active.client_secret' => 'proxy-shared-secret']);

        $this->postJson(route('student-discounts.public.claims.store').'?shop=student-test.myshopify.com&timestamp='.now()->timestamp.'&signature=invalid', [
            'email' => 'student@school.edu',
            'idempotency_key' => 'invalid-signature-001',
        ])->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_APP_PROXY_SIGNATURE');

        $path = 'student-discounts/1/1/test/student-id.jpg';
        Storage::disk('local')->put($path, 'private-image-bytes');
        $claim = StudentDiscountClaim::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'email' => 'old@example.com',
            'normalized_email' => 'old@example.com',
            'source' => 'student_id',
            'status' => 'approved',
            'review_method' => 'manual',
            'evidence_disk' => 'local',
            'evidence_path' => $path,
            'evidence_mime' => 'image/jpeg',
            'evidence_size' => 20,
            'reviewed_at' => now()->subDays(31),
            'claim_token_hash' => hash('sha256', 'token'),
            'claim_token_encrypted' => 'token',
        ]);

        $this->artisan('student-discounts:prune-evidence --days=30')->assertSuccessful();
        Storage::disk('local')->assertMissing($path);
        $this->assertNull($claim->fresh()->evidence_path);
        $this->assertNotNull($claim->fresh()->evidence_deleted_at);
    }

    public function test_store_operator_can_review_only_an_explicitly_authorized_organization_and_store(): void
    {
        Mail::fake();
        Queue::fake();
        [$operator, $organization, $store] = $this->context('operator');
        $campaign = $this->campaign($organization, $store, ['enabled' => true]);
        $connection = $this->connection($store);
        $this->studentInstallation($store, $connection);
        $claim = StudentDiscountClaim::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'email' => 'review@example.com',
            'normalized_email' => 'review@example.com',
            'source' => 'student_id',
            'status' => 'pending',
            'claim_token_hash' => hash('sha256', 'review-token'),
            'claim_token_encrypted' => 'review-token',
        ]);
        Http::fakeSequence('https://student-test.myshopify.com/*')
            ->push(['data' => ['codeDiscountNodeByCode' => null]])
            ->push(['data' => ['discountCodeBasicCreate' => [
                'codeDiscountNode' => ['id' => 'gid://shopify/DiscountCodeNode/456'],
                'userErrors' => [],
            ]]]);

        $base = route('student-discounts.index', [$organization, $store]);
        $this->actingAs($operator)->get($base)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('StudentDiscounts/Index')
            ->where('organization.id', $organization->id)
            ->where('store.id', $store->id)
            ->where('permissions.approve', true)
            ->where('permissions.manageCampaign', false)
            ->has('claims.data', 1));

        $this->actingAs($operator)
            ->post(route('student-discounts.claims.approve', [$organization, $store, $claim]))
            ->assertRedirect();
        $this->assertSame('approved', $claim->fresh()->status);
        $this->assertSame($operator->id, $claim->fresh()->reviewed_by);
        $this->assertSame($campaign->usage_limit, $claim->fresh()->discountCode->usage_limit);
        $approvedCode = $claim->fresh()->discountCode;
        Queue::assertPushed(SendStudentDiscountDecisionMail::class, fn (SendStudentDiscountDecisionMail $job): bool => $job->claimId === $claim->id
            && $job->codeId === $approvedCode->id);

        $rejectedClaim = StudentDiscountClaim::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'email' => 'rejected-review@example.com',
            'normalized_email' => 'rejected-review@example.com',
            'source' => 'student_id',
            'status' => 'pending',
            'claim_token_hash' => hash('sha256', 'rejected-review-token'),
            'claim_token_encrypted' => 'rejected-review-token',
        ]);
        $this->actingAs($operator)
            ->post(route('student-discounts.claims.reject', [$organization, $store, $rejectedClaim]), ['reason' => '证据无法验证'])
            ->assertRedirect();
        $this->assertSame('rejected', $rejectedClaim->fresh()->status);
        Queue::assertPushed(SendStudentDiscountDecisionMail::class, fn (SendStudentDiscountDecisionMail $job): bool => $job->claimId === $rejectedClaim->id
            && $job->codeId === null);
        Queue::assertPushed(SendStudentDiscountDecisionMail::class, 2);
        Mail::assertNothingSent();

        $otherStore = $organization->stores()->create([
            'name' => 'Other Store',
            'shopify_domain' => 'other-student-test.myshopify.com',
            'status' => 'active',
        ]);
        $this->actingAs($operator)
            ->get(route('student-discounts.index', [$organization, $otherStore]))
            ->assertForbidden();
    }

    public function test_decision_mail_job_marks_claim_and_code_sent_once_without_serializing_pii(): void
    {
        Mail::fake();
        [, $organization, $store] = $this->context('store-admin');
        $claim = StudentDiscountClaim::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'email' => 'queued-student@example.edu',
            'normalized_email' => 'queued-student@example.edu',
            'source' => 'education_email',
            'status' => 'approved',
            'claim_token_hash' => hash('sha256', 'queued-claim-token'),
            'claim_token_encrypted' => 'queued-claim-token',
            'email_failed_at' => now()->subMinute(),
        ]);
        $code = StudentDiscountCode::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'claim_id' => $claim->id,
            'normalized_email' => $claim->normalized_email,
            'shopify_discount_id' => 'gid://shopify/DiscountCodeNode/mail-success',
            'code' => 'STUDENT-MAIL-SUCCESS',
            'status' => 'unused',
            'usage_count' => 0,
            'usage_limit' => 1,
            'idempotency_key' => 'claim:'.$claim->uuid,
            'generated_at' => now(),
            'expires_at' => now()->addDays(7),
            'email_failed_at' => now()->subMinute(),
        ]);
        $job = new SendStudentDiscountDecisionMail($organization->id, $store->id, $claim->id, $code->id);

        $this->assertStringNotContainsString($claim->email, serialize($job));
        $this->assertStringNotContainsString($claim->claim_token_encrypted, serialize($job));
        $job->handle();
        $job->handle();

        Mail::assertSent(StudentDiscountDecisionMail::class, function (StudentDiscountDecisionMail $mail) use ($claim, $code): bool {
            return $mail->hasTo($claim->email) && $mail->discountCode->is($code);
        });
        Mail::assertSent(StudentDiscountDecisionMail::class, 1);
        $this->assertNotNull($claim->fresh()->email_sent_at);
        $this->assertNull($claim->fresh()->email_failed_at);
        $this->assertNotNull($code->fresh()->email_sent_at);
        $this->assertNull($code->fresh()->email_failed_at);
    }

    public function test_decision_mail_job_sends_outside_a_transaction_and_preserves_concurrent_success_markers(): void
    {
        [, $organization, $store] = $this->context('store-admin');
        $claim = StudentDiscountClaim::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'email' => 'concurrent-student@example.edu',
            'normalized_email' => 'concurrent-student@example.edu',
            'source' => 'education_email',
            'status' => 'approved',
            'claim_token_hash' => hash('sha256', 'concurrent-claim-token'),
            'claim_token_encrypted' => 'concurrent-claim-token',
            'email_failed_at' => now()->subMinute(),
        ]);
        $code = StudentDiscountCode::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'claim_id' => $claim->id,
            'normalized_email' => $claim->normalized_email,
            'shopify_discount_id' => 'gid://shopify/DiscountCodeNode/mail-concurrent',
            'code' => 'STUDENT-MAIL-CONCURRENT',
            'status' => 'unused',
            'usage_count' => 0,
            'usage_limit' => 1,
            'idempotency_key' => 'claim:'.$claim->uuid,
            'generated_at' => now(),
            'expires_at' => now()->addDays(7),
            'email_failed_at' => now()->subMinute(),
        ]);
        $concurrentSentAt = now()->subSeconds(5)->startOfSecond();
        $transactionLevel = DB::transactionLevel();
        $pendingMail = \Mockery::mock();
        $pendingMail->shouldReceive('send')
            ->once()
            ->with(\Mockery::type(StudentDiscountDecisionMail::class))
            ->andReturnUsing(function (StudentDiscountDecisionMail $mail) use ($claim, $code, $concurrentSentAt, $transactionLevel): void {
                $this->assertSame($transactionLevel, DB::transactionLevel());
                $this->assertTrue($mail->discountCode->is($code));
                StudentDiscountClaim::query()->whereKey($claim->id)->update([
                    'email_sent_at' => $concurrentSentAt,
                    'email_failed_at' => null,
                ]);
                StudentDiscountCode::query()->whereKey($code->id)->update([
                    'email_sent_at' => $concurrentSentAt,
                    'email_failed_at' => null,
                ]);
            });
        Mail::shouldReceive('to')
            ->once()
            ->with($claim->email)
            ->andReturn($pendingMail);

        $job = new SendStudentDiscountDecisionMail($organization->id, $store->id, $claim->id, $code->id);
        $job->handle();

        $this->assertSame($concurrentSentAt->toISOString(), $claim->fresh()->email_sent_at?->toISOString());
        $this->assertSame($concurrentSentAt->toISOString(), $code->fresh()->email_sent_at?->toISOString());
        $this->assertNull($claim->fresh()->email_failed_at);
        $this->assertNull($code->fresh()->email_failed_at);
    }

    public function test_decision_mail_job_marks_claim_and_code_failed_with_sanitized_exception(): void
    {
        [, $organization, $store] = $this->context('store-admin');
        $claim = StudentDiscountClaim::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'email' => 'failed-student@example.edu',
            'normalized_email' => 'failed-student@example.edu',
            'source' => 'education_email',
            'status' => 'approved',
            'claim_token_hash' => hash('sha256', 'failed-claim-token'),
            'claim_token_encrypted' => 'failed-claim-token',
        ]);
        $code = StudentDiscountCode::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'claim_id' => $claim->id,
            'normalized_email' => $claim->normalized_email,
            'shopify_discount_id' => 'gid://shopify/DiscountCodeNode/mail-failure',
            'code' => 'STUDENT-MAIL-FAILURE',
            'status' => 'unused',
            'usage_count' => 0,
            'usage_limit' => 1,
            'idempotency_key' => 'claim:'.$claim->uuid,
            'generated_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
        $job = new SendStudentDiscountDecisionMail($organization->id, $store->id, $claim->id, $code->id);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('sensitive transport detail'));

        try {
            $job->handle();
            $this->fail('The mail job should throw a sanitized exception.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Student discount decision email delivery failed.', $exception->getMessage());
            $this->assertStringNotContainsString('sensitive transport detail', $exception->getMessage());
            $this->assertStringNotContainsString($claim->email, $exception->getMessage());
            $job->failed($exception);
        }

        $this->assertNull($claim->fresh()->email_sent_at);
        $this->assertNotNull($claim->fresh()->email_failed_at);
        $this->assertNull($code->fresh()->email_sent_at);
        $this->assertNotNull($code->fresh()->email_failed_at);
    }

    public function test_shopify_app_connection_validates_oidc_claims_and_bootstrap_sets_installation_proxy_path(): void
    {
        [, , $store] = $this->context('store-admin');
        $connection = $this->connection($store);
        config([
            'student_discount.environment' => 'local',
            'student_discount.active.client_id' => 'student-app-client-id',
            'student_discount.active.client_secret' => 'student-app-client-secret',
            'student_discount.active.proxy_path' => '/apps/deco-student-local',
            'student_discount.required_scopes' => ['read_discounts', 'write_discounts', 'read_products', 'write_app_proxy'],
            'shopify.api_version' => '2026-07',
        ]);
        $token = $this->shopifyIdToken($store->shopify_domain, 'student-app-client-id', 'student-app-client-secret');

        $this->withToken($token)
            ->getJson(route('student-discounts.shopify-app.connection', ['shop' => $store->shopify_domain]))
            ->assertOk()
            ->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.environment', 'local')
            ->assertJsonPath('data.store.id', $store->id)
            ->assertJsonPath('data.store.shopify_domain', $store->shopify_domain);

        Http::fakeSequence("https://{$store->shopify_domain}/*")
            ->push([
                'access_token' => 'student-offline-access-token',
                'refresh_token' => 'student-offline-refresh-token',
                'expires_in' => 3600,
                'refresh_token_expires_in' => 7776000,
                'scope' => 'read_discounts,write_discounts,read_products,write_app_proxy',
            ])
            ->push(['data' => ['currentAppInstallation' => [
                'id' => 'gid://shopify/AppInstallation/789',
                'accessScopes' => [
                    ['handle' => 'read_discounts'],
                    ['handle' => 'write_discounts'],
                    ['handle' => 'read_products'],
                    ['handle' => 'write_app_proxy'],
                ],
            ]]])
            ->push(['data' => ['metafieldsSet' => [
                'metafields' => [[
                    'id' => 'gid://shopify/Metafield/999',
                    'namespace' => 'deco_student_discount',
                    'key' => 'proxy_path',
                    'value' => '/apps/deco-student-local',
                ]],
                'userErrors' => [],
            ]]])
            ->push([
                'access_token' => 'student-offline-access-token-rotated',
                'refresh_token' => 'student-offline-refresh-token-rotated',
                'expires_in' => 3600,
                'refresh_token_expires_in' => 7776000,
                'scope' => 'read_discounts,write_discounts,read_products,write_app_proxy',
            ])
            ->push(['data' => ['currentAppInstallation' => [
                'id' => 'gid://shopify/AppInstallation/789',
                'accessScopes' => [
                    ['handle' => 'read_discounts'],
                    ['handle' => 'write_discounts'],
                    ['handle' => 'read_products'],
                    ['handle' => 'write_app_proxy'],
                ],
            ]]])
            ->push(['data' => ['metafieldsSet' => [
                'metafields' => [[
                    'id' => 'gid://shopify/Metafield/999',
                    'namespace' => 'deco_student_discount',
                    'key' => 'proxy_path',
                    'value' => '/apps/deco-student-local',
                ]],
                'userErrors' => [],
            ]]]);

        $this->withToken($token)
            ->postJson(route('student-discounts.shopify-app.bootstrap', ['shop' => $store->shopify_domain]))
            ->assertOk()
            ->assertJsonPath('data.environment', 'local')
            ->assertJsonPath('data.app_installation_id', 'gid://shopify/AppInstallation/789')
            ->assertJsonPath('data.proxy_path', '/apps/deco-student-local');

        $this->withToken($token)
            ->postJson(route('student-discounts.shopify-app.bootstrap', ['shop' => $store->shopify_domain]))
            ->assertOk();

        Http::assertSent(fn ($request): bool => $request->url() === "https://{$store->shopify_domain}/admin/oauth/access_token"
            && $request['subject_token'] === $token
            && $request['requested_token_type'] === 'urn:shopify:params:oauth:token-type:offline-access-token'
            && (int) $request['expiring'] === 1);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/admin/api/2026-07/graphql.json')
            && str_contains($request->body(), '"variables":{}')
            && str_contains((string) $request['query'], 'currentAppInstallation'));
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/admin/api/2026-07/graphql.json')
            && data_get($request->data(), 'variables.metafields.0.ownerId') === 'gid://shopify/AppInstallation/789'
            && data_get($request->data(), 'variables.metafields.0.namespace') === 'deco_student_discount'
            && data_get($request->data(), 'variables.metafields.0.key') === 'proxy_path'
            && data_get($request->data(), 'variables.metafields.0.value') === '/apps/deco-student-local');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'student_discount_shopify_app_bootstrapped',
            'store_id' => $store->id,
        ]);
        $app = App::query()->sole();
        $this->assertSame(config('student_discount.active.handle'), $app->handle);
        $installation = AppInstallation::query()->whereBelongsTo($app)->whereBelongsTo($store)->sole();
        $this->assertSame('active', $installation->status);
        $this->assertSame($connection->id, $installation->shopify_connection_id);
        $this->assertSame('gid://shopify/AppInstallation/789', $installation->external_installation_id);
        $this->assertSame(
            ['read_discounts', 'read_products', 'write_app_proxy', 'write_discounts'],
            $installation->granted_scopes,
        );
        $this->assertSame('student_discount_bootstrap', data_get($installation->settings, 'source'));
        $this->assertSame('offline', $installation->token_type);
        $this->assertSame('student-offline-access-token-rotated', $installation->access_token_encrypted);
        $this->assertSame('student-offline-refresh-token-rotated', $installation->refresh_token_encrypted);
        $this->assertNotNull($installation->access_token_expires_at);
        $this->assertNotNull($installation->refresh_token_expires_at);
        $this->assertArrayNotHasKey('access_token_encrypted', $installation->toArray());
        $this->assertArrayNotHasKey('refresh_token_encrypted', $installation->toArray());
        $this->assertStringNotContainsString(
            'student-offline-access-token-rotated',
            (string) DB::table('app_installations')->where('id', $installation->id)->value('access_token_encrypted'),
        );
        $this->assertDatabaseCount('apps', 1);
        $this->assertDatabaseCount('app_installations', 1);
    }

    public function test_shopify_app_bootstrap_rejects_token_without_required_scopes(): void
    {
        [, , $store] = $this->context('store-admin');
        config([
            'student_discount.active.client_id' => 'student-app-client-id',
            'student_discount.active.client_secret' => 'student-app-client-secret',
            'student_discount.required_scopes' => ['read_discounts', 'write_discounts', 'read_products', 'write_app_proxy'],
        ]);
        $token = $this->shopifyIdToken($store->shopify_domain, 'student-app-client-id', 'student-app-client-secret');
        Http::fakeSequence("https://{$store->shopify_domain}/*")
            ->push([
                'access_token' => 'limited-offline-access-token',
                'refresh_token' => 'limited-offline-refresh-token',
                'expires_in' => 3600,
                'refresh_token_expires_in' => 7776000,
                'scope' => 'read_products,write_app_proxy',
            ]);

        $this->withToken($token)
            ->postJson(route('student-discounts.shopify-app.bootstrap', ['shop' => $store->shopify_domain]))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'SHOPIFY_REQUIRED_SCOPES_MISSING');

        Http::assertSentCount(1);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'student_discount_shopify_app_bootstrapped',
            'store_id' => $store->id,
        ]);
    }

    public function test_shopify_app_bootstrap_rejects_installation_without_required_scopes(): void
    {
        [, , $store] = $this->context('store-admin');
        config([
            'student_discount.active.client_id' => 'student-app-client-id',
            'student_discount.active.client_secret' => 'student-app-client-secret',
            'student_discount.required_scopes' => ['read_discounts', 'write_discounts', 'read_products', 'write_app_proxy'],
        ]);
        $token = $this->shopifyIdToken($store->shopify_domain, 'student-app-client-id', 'student-app-client-secret');
        Http::fakeSequence("https://{$store->shopify_domain}/*")
            ->push([
                'access_token' => 'student-offline-access-token',
                'refresh_token' => 'student-offline-refresh-token',
                'expires_in' => 3600,
                'refresh_token_expires_in' => 7776000,
                'scope' => 'read_discounts,write_discounts,read_products,write_app_proxy',
            ])
            ->push(['data' => ['currentAppInstallation' => [
                'id' => 'gid://shopify/AppInstallation/789',
                'accessScopes' => [
                    ['handle' => 'read_products'],
                    ['handle' => 'write_app_proxy'],
                ],
            ]]]);

        $this->withToken($token)
            ->postJson(route('student-discounts.shopify-app.bootstrap', ['shop' => $store->shopify_domain]))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'SHOPIFY_REQUIRED_SCOPES_MISSING');

        Http::assertSentCount(2);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'student_discount_shopify_app_bootstrapped',
            'store_id' => $store->id,
        ]);
    }

    public function test_shopify_app_connection_rejects_wrong_audience_or_shop(): void
    {
        [, , $store] = $this->context('store-admin');
        config([
            'student_discount.active.client_id' => 'expected-client-id',
            'student_discount.active.client_secret' => 'student-app-client-secret',
        ]);
        $wrongAudience = $this->shopifyIdToken($store->shopify_domain, 'wrong-client-id', 'student-app-client-secret');

        $this->withToken($wrongAudience)
            ->getJson(route('student-discounts.shopify-app.connection', ['shop' => $store->shopify_domain]))
            ->assertUnauthorized()
            ->assertHeader('X-Shopify-Retry-Invalid-Session-Request', '1')
            ->assertJsonPath('error.code', 'INVALID_SHOPIFY_ID_TOKEN');

        $validToken = $this->shopifyIdToken($store->shopify_domain, 'expected-client-id', 'student-app-client-secret');
        $this->withToken($validToken)
            ->getJson(route('student-discounts.shopify-app.connection', ['shop' => 'other-shop.myshopify.com']))
            ->assertUnauthorized();
    }

    /** @return array{0: User, 1: Organization, 2: Store} */
    private function context(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'DecoMKT', 'code' => 'decomkt', 'status' => 'active']);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create([
            'name' => 'Student Test',
            'shopify_domain' => 'student-test.myshopify.com',
            'status' => 'active',
            'currency' => 'USD',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, [
            'organization_id' => $organization->id,
            'store_id' => in_array($roleSlug, ['store-admin', 'operator'], true) ? $store->id : null,
        ]);

        return [$user, $organization, $store];
    }

    private function campaign(Organization $organization, Store $store, array $overrides = []): StudentDiscountCampaign
    {
        return StudentDiscountCampaign::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'enabled' => false,
            'code_prefix' => 'STUDENT',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'applies_to' => 'all',
            'target_ids' => [],
            'usage_limit' => 1,
            'validity_days' => 7,
            'education_email_domains' => [],
            ...$overrides,
        ]);
    }

    private function connection(Store $store): ShopifyConnection
    {
        return ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'shopify-test-token',
            'scopes' => ['read_discounts', 'write_discounts'],
            'api_version' => '2026-07',
            'status' => 'connected',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function studentInstallation(
        Store $store,
        ShopifyConnection $connection,
        array $overrides = [],
    ): AppInstallation {
        if (! filled(config('student_discount.active.client_secret'))) {
            config(['student_discount.active.client_secret' => 'student-app-test-secret']);
        }

        $app = App::query()->firstOrCreate(
            ['handle' => (string) config('student_discount.active.handle')],
            [
                'organization_id' => null,
                'name' => (string) config('student_discount.active.name'),
                'client_id' => (string) config('student_discount.active.client_id'),
                'client_secret_encrypted' => (string) config('student_discount.active.client_secret'),
                'distribution' => 'custom',
                'status' => 'active',
                'scopes' => (array) config('student_discount.required_scopes'),
                'webhook_api_version' => (string) config('shopify.api_version'),
            ],
        );

        return AppInstallation::query()->create([
            'app_id' => $app->id,
            'store_id' => $store->id,
            'shopify_connection_id' => $connection->id,
            'status' => 'active',
            'granted_scopes' => (array) config('student_discount.required_scopes'),
            'access_token_encrypted' => 'student-app-offline-token',
            'refresh_token_encrypted' => 'student-app-refresh-token',
            'token_type' => 'offline',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(90),
            'installed_at' => now(),
            ...$overrides,
        ]);
    }

    private function setting(string $section, string $key, mixed $value, bool $secret, ?User $user): SystemSetting
    {
        return SystemSetting::query()->create([
            'section' => $section,
            'key' => $key,
            'value' => json_encode($value, JSON_THROW_ON_ERROR),
            'is_secret' => $secret,
            'updated_by' => $user?->id,
        ]);
    }

    private function storedValue(string $section, string $key): mixed
    {
        return json_decode((string) SystemSetting::query()->where('section', $section)->where('key', $key)->value('value'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function signedProxyUrl(string $base, string $shop): string
    {
        $parameters = [
            'logged_in_customer_id' => '',
            'path_prefix' => '/apps/student-discount',
            'shop' => $shop,
            'timestamp' => (string) now()->timestamp,
        ];
        $pieces = collect($parameters)->map(fn ($value, $key): string => $key.'='.$value)->sort()->implode('');
        $parameters['signature'] = hash_hmac('sha256', $pieces, (string) config('student_discount.active.client_secret'));

        return $base.'?'.http_build_query($parameters);
    }

    private function shopifyIdToken(string $shop, string $audience, string $secret): string
    {
        $encode = fn (array $value): string => rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $header = $encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $payload = $encode([
            'iss' => "https://{$shop}/admin",
            'dest' => "https://{$shop}",
            'aud' => $audience,
            'sub' => '42',
            'exp' => now()->addMinute()->timestamp,
            'nbf' => now()->subSecond()->timestamp,
            'iat' => now()->timestamp,
            'jti' => (string) Str::uuid(),
        ]);
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $header.'.'.$payload, $secret, true)), '+/', '-_'), '=');

        return $header.'.'.$payload.'.'.$signature;
    }

    /** @return array{current_organization_id: int, current_store_id: int} */
    private function contextSession(Organization $organization, Store $store): array
    {
        return ['current_organization_id' => $organization->id, 'current_store_id' => $store->id];
    }
}
