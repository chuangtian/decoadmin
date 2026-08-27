<?php

namespace Tests\Feature;

use App\Exceptions\StudentDiscountException;
use App\Jobs\DeleteSupersededStudentDiscountEvidence;
use App\Jobs\SendStudentDiscountDecisionMail;
use App\Jobs\SyncStudentDiscountCodeUsage;
use App\Mail\StudentDiscountDecisionMail;
use App\Mail\StudentDiscountTemplatePreviewMail;
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
use App\Models\StudentDiscountEvidenceDeletion;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\StudentDiscount\GeminiStudentIdRecognitionService;
use App\Services\StudentDiscount\StudentDiscountClaimService;
use App\Services\StudentDiscount\StudentDiscountCodeService;
use App\Services\StudentDiscount\StudentDiscountEvidenceCleanupService;
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

    public function test_student_id_claim_requires_name_privacy_consent_and_safe_evidence_fields(): void
    {
        [, $organization, $store] = $this->context('store-admin');
        $this->campaign($organization, $store, ['enabled' => true]);
        config(['student_discount.active.client_secret' => 'proxy-shared-secret']);
        $url = fn (): string => $this->signedProxyUrl(
            route('student-discounts.public.claims.store'),
            $store->shopify_domain,
        );

        $this->postJson($url(), [
            'email' => 'student@example.com',
            'idempotency_key' => 'email-only-contract-001',
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'EVIDENCE_REQUIRED');

        $this->post($url(), [
            'email' => 'student@example.com',
            'idempotency_key' => 'missing-identity-001',
            'evidence' => UploadedFile::fake()->create('student-id.jpg', 32, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['fields' => ['name', 'privacy_consent']]]);

        $this->post($url(), [
            'name' => '<script>',
            'email' => 'student@example.com',
            'privacy_consent' => true,
            'idempotency_key' => 'invalid-name-001',
            'evidence' => UploadedFile::fake()->create('student-id.jpg', 32, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['name']]]);

        $this->post($url(), [
            'name' => 'Student Example',
            'email' => 'student@example.com',
            'privacy_consent' => false,
            'idempotency_key' => 'missing-consent-001',
            'evidence' => UploadedFile::fake()->create('student-id.jpg', 32, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['privacy_consent']]]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.55'])->post($url(), [
            'name' => 'Student Example',
            'email' => 'student@example.com',
            'privacy_consent' => true,
            'idempotency_key' => 'invalid-evidence-001',
            'evidence' => UploadedFile::fake()->create('student-id.pdf', 32, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['evidence']]]);

        $this->post($url(), [
            'name' => 'Student Example',
            'email' => 'student@example.com',
            'privacy_consent' => true,
            'idempotency_key' => 'oversized-evidence-001',
            'evidence' => UploadedFile::fake()->create('student-id.jpg', 5121, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['evidence']]]);

        $this->assertDatabaseCount('student_discount_claims', 0);
    }

    public function test_shopify_app_proxy_preserves_structured_business_errors_over_successful_transport(): void
    {
        [, $organization, $store] = $this->context('store-admin');
        $this->campaign($organization, $store, ['enabled' => true]);
        config([
            'student_discount.active.client_secret' => 'proxy-shared-secret',
            'student_discount.active.proxy_path' => '/apps/student-discount',
        ]);

        $this->postJson($this->signedProxyUrl(
            route('student-discounts.public.claims.store'),
            $store->shopify_domain,
        ), [
            'email' => 'student@example.com',
            'idempotency_key' => 'proxy-error-transport-001',
        ])->assertOk()
            ->assertHeader('X-Student-Discount-Status', '422')
            ->assertJsonPath('error.code', 'EVIDENCE_REQUIRED')
            ->assertJsonPath('error.status', 422);

        $this->assertDatabaseCount('student_discount_claims', 0);
    }

    public function test_submission_rate_limit_runs_after_verified_store_resolution_and_isolated_by_store_and_ip(): void
    {
        [, $organization, $store] = $this->context('store-admin');
        $otherStore = $organization->stores()->create([
            'name' => 'Other Rate Limit Store',
            'shopify_domain' => 'other-rate-limit.myshopify.com',
            'status' => 'active',
        ]);
        config(['student_discount.active.client_secret' => 'proxy-shared-secret']);
        $ip = '203.0.113.42';
        $payload = fn (int $attempt): array => [
            'name' => 'Rate Limit Student',
            'privacy_consent' => true,
            'idempotency_key' => "rate-limit-{$attempt}",
            'shop' => $otherStore->shopify_domain,
            'store_id' => $otherStore->id,
            'organization_id' => 999999,
        ];

        for ($attempt = 1; $attempt <= 8; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson(
                route('student-discounts.public.claims.store').'?shop='.$store->shopify_domain.'&timestamp='.now()->timestamp.'&signature=invalid',
                $payload($attempt),
            )->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_APP_PROXY_SIGNATURE');
        }

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson(
                $this->signedProxyUrl(route('student-discounts.public.claims.store'), $store->shopify_domain),
                $payload($attempt),
            )->assertUnprocessable();
        }

        $limited = $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson(
            $this->signedProxyUrl(route('student-discounts.public.claims.store'), $store->shopify_domain),
            $payload(6),
        );
        $limited->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('error.code', 'STUDENT_DISCOUNT_RATE_LIMITED')
            ->assertJsonPath('error.message', '提交过于频繁，请稍后重试。')
            ->assertDontSee($ip);
        $this->assertGreaterThan(0, (int) $limited->json('error.retry_after'));

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson(
                $this->signedProxyUrl(route('student-discounts.public.claims.store'), $otherStore->shopify_domain),
                $payload(100 + $attempt),
            )->assertUnprocessable();
        }
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson(
            $this->signedProxyUrl(route('student-discounts.public.claims.store'), $otherStore->shopify_domain),
            $payload(106),
        )->assertStatus(429)->assertJsonPath('error.code', 'STUDENT_DISCOUNT_RATE_LIMITED');
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
            ]]])
            ->push(['data' => ['codeDiscountNodeByCode' => [
                'id' => 'gid://shopify/DiscountCodeNode/123',
                'codeDiscount' => [
                    'endsAt' => now()->addDays(7)->toIso8601String(),
                    'usageLimit' => 1,
                    'codes' => ['nodes' => [[
                        'code' => 'STUDENT-EXISTING',
                        'asyncUsageCount' => 0,
                    ]]],
                ],
            ]]]);

        $url = $this->signedProxyUrl(route('student-discounts.public.claims.store'), 'student-test.myshopify.com');
        $first = $this->postJson($url, [
            'email' => 'student@school.edu',
            'idempotency_key' => 'claim-fast-pass-001',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.discount.status', 'unused')
            ->assertJsonPath('data.discount.usage_limit', 1);

        $code = StudentDiscountCode::query()->sole();
        $createdClaim = $code->claim;
        $this->assertNull($createdClaim->name);
        $this->assertNull($createdClaim->privacy_consented_at);
        $this->assertSame('gid://shopify/DiscountCodeNode/123', $code->shopify_discount_id);
        $this->assertEquals(7, $code->generated_at->diffInDays($code->expires_at));
        Mail::assertNothingSent();

        $secondUrl = $this->signedProxyUrl(route('student-discounts.public.claims.store'), 'student-test.myshopify.com');
        $this->postJson($secondUrl, [
            'email' => 'student@school.edu',
            'idempotency_key' => 'claim-fast-pass-001',
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.discount.code', $first->json('data.discount.code'));

        $this->postJson($this->signedProxyUrl(route('student-discounts.public.claims.store'), 'student-test.myshopify.com'), [
            'email' => 'student@school.edu',
            'idempotency_key' => 'claim-fast-pass-002',
        ])
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
        Http::assertSentCount(3);
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

        $payload = [
            'name' => 'Student Example',
            'email' => 'student@school.edu',
            'privacy_consent' => true,
            'idempotency_key' => 'claim-current-store-token-001',
        ];
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
            'name' => 'Student Example',
            'email' => 'student@school.edu',
            'privacy_consent' => true,
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
        $this->setting('student_ai', 'auto_approval_threshold', 60, false, null);
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
            'name' => 'Student Example',
            'email' => 'student@example.com',
            'privacy_consent' => true,
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

    public function test_sample_watermark_does_not_prevent_student_card_document_from_auto_approval(): void
    {
        Storage::fake('local');
        Mail::fake();
        Queue::fake();
        [, $organization, $store] = $this->context('store-admin');
        $this->campaign($organization, $store, ['enabled' => true]);
        $connection = $this->connection($store);
        $this->studentInstallation($store, $connection);
        $this->setting('student_ai', 'gemini_api_key', 'test-gemini-key', true, null);
        $this->setting('student_ai', 'gemini_model', 'gemini-2.5-pro', false, null);
        $this->setting('student_ai', 'auto_approval_threshold', 80, false, null);
        config(['student_discount.active.client_secret' => 'proxy-shared-secret']);

        $evidence = UploadedFile::fake()->create('test-sample-student-id.jpg', 64, 'image/jpeg');
        file_put_contents((string) $evidence->getRealPath(), "\nTEST SAMPLE — NOT VALID", FILE_APPEND);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'is_student_id' => true,
                    'institution_name' => 'Example University',
                    'student_name' => null,
                    'student_identifier_masked' => '***1234',
                    'expiry_date' => null,
                    'confidence' => 94,
                    'review_notes' => 'Student identity card layout with a readable institution field.',
                ], JSON_THROW_ON_ERROR)]]]]],
            ]),
            "https://{$store->shopify_domain}/*" => Http::sequence()
                ->push(['data' => ['codeDiscountNodeByCode' => null]])
                ->push(['data' => ['discountCodeBasicCreate' => [
                    'codeDiscountNode' => ['id' => 'gid://shopify/DiscountCodeNode/sample-watermark'],
                    'userErrors' => [],
                ]]]),
        ]);

        $response = $this->post($this->signedProxyUrl(route('student-discounts.public.claims.store'), $store->shopify_domain), [
            'name' => 'Student Example',
            'email' => 'student@example.com',
            'privacy_consent' => true,
            'idempotency_key' => 'claim-sample-watermark-001',
            'evidence' => $evidence,
        ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJsonPath('data.status', 'approved');
        $claim = StudentDiscountClaim::query()->sole();
        $this->assertSame('approved', $claim->status);
        $this->assertSame('ai', $claim->review_method);
        $this->assertSame('94.00', $claim->confidence);
        $this->assertTrue(data_get($claim->recognition_result, 'is_student_id'));
        $this->assertDatabaseHas('student_discount_codes', [
            'store_id' => $store->id,
            'claim_id' => $claim->id,
            'shopify_discount_id' => 'gid://shopify/DiscountCodeNode/sample-watermark',
        ]);
        Queue::assertPushed(SendStudentDiscountDecisionMail::class, 1);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'generativelanguage.googleapis.com')) {
                return false;
            }

            $prompt = mb_strtolower((string) data_get($request->data(), 'contents.0.parts.0.text'));
            $encodedEvidence = data_get($request->data(), 'contents.0.parts.1.inlineData.data');
            $sentEvidence = is_string($encodedEvidence) ? base64_decode($encodedEvidence, true) : false;

            return str_contains($prompt, 'only visual document-type recognition')
                && str_contains($prompt, 'school name or logo')
                && str_contains($prompt, 'portrait photo')
                && str_contains($prompt, 'test sample')
                && str_contains($prompt, 'not valid')
                && str_contains($prompt, 'must not cause is_student_id to be false')
                && str_contains($prompt, 'not owned by the submitter')
                && str_contains($prompt, 'displayed name might not match a submitted name')
                && str_contains($prompt, 'current enrollment cannot be proven')
                && str_contains($prompt, 'confidence must not measure authenticity or validity')
                && is_string($sentEvidence)
                && str_contains($sentEvidence, 'TEST SAMPLE — NOT VALID');
        });
    }

    public function test_gemini_provider_failures_map_to_safe_stable_codes_without_retaining_provider_text(): void
    {
        Storage::fake('local');
        [, $organization, $store] = $this->context('store-admin');
        $this->setting('student_ai', 'gemini_api_key', 'synthetic-test-key', true, null);
        $this->setting('student_ai', 'gemini_model', 'gemini-test-model', false, null);
        $path = "student-discounts/{$organization->id}/{$store->id}/safe-errors/student-id.jpg";
        Storage::disk('local')->put($path, 'synthetic-image-bytes');
        $claim = $this->claim($organization, $store, 'safe-errors', [
            'evidence_disk' => 'local',
            'evidence_path' => $path,
            'evidence_mime' => 'image/jpeg',
        ]);
        $service = app(GeminiStudentIdRecognitionService::class);
        $cases = [
            'invalid_api_key' => [400, 'INVALID_ARGUMENT', 'API key not valid. private-provider-detail', 'API_KEY_INVALID'],
            'permission_denied' => [403, 'PERMISSION_DENIED', 'Private permission detail', 'ACCESS_DENIED'],
            'model_not_found' => [404, 'NOT_FOUND', 'Requested model not found. private detail', 'MODEL_NOT_FOUND'],
            'rate_limited' => [429, 'RESOURCE_EXHAUSTED', 'Private quota detail', 'RATE_LIMITED'],
            'api_error' => [500, 'INTERNAL', 'Private provider failure detail', 'INTERNAL'],
        ];
        $sequence = Http::fakeSequence('https://generativelanguage.googleapis.com/*');
        foreach ($cases as [$status, $providerStatus, $message, $reason]) {
            $sequence->push([
                'error' => [
                    'status' => $providerStatus,
                    'message' => $message,
                    'details' => [['reason' => $reason]],
                ],
            ], $status);
        }

        foreach (array_keys($cases) as $expected) {
            $result = $service->recognize($claim);
            $this->assertFalse($result['ok']);
            $this->assertSame($expected, $result['failure_code']);
            $this->assertNull($result['result']);
            $encoded = json_encode($result, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('private', strtolower($encoded));
            $this->assertStringNotContainsString('synthetic-test-key', $encoded);
        }
    }

    public function test_invalid_gemini_key_failure_is_stored_and_displayed_only_as_safe_code(): void
    {
        Storage::fake('local');
        [$admin, $organization, $store] = $this->context('store-admin');
        $this->campaign($organization, $store, ['enabled' => true]);
        $this->setting('student_ai', 'gemini_api_key', 'synthetic-invalid-key', true, null);
        $this->setting('student_ai', 'gemini_model', 'gemini-test-model', false, null);
        $this->setting('student_ai', 'auto_approval_threshold', 80, false, null);
        config(['student_discount.active.client_secret' => 'proxy-shared-secret']);
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'status' => 'INVALID_ARGUMENT',
                    'message' => 'API key not valid. private-provider-detail',
                    'details' => [['reason' => 'API_KEY_INVALID']],
                ],
            ], 400),
        ]);

        $response = $this->post($this->signedProxyUrl(route('student-discounts.public.claims.store'), $store->shopify_domain), [
            'name' => 'Student Example',
            'email' => 'safe-failure@example.com',
            'privacy_consent' => true,
            'idempotency_key' => 'safe-failure-001',
            'evidence' => UploadedFile::fake()->create('student-id.jpg', 64, 'image/jpeg'),
        ], ['Accept' => 'application/json']);

        $response->assertAccepted()->assertJsonPath('data.status', 'pending')->assertDontSee('private-provider-detail');
        $claim = StudentDiscountClaim::query()->sole();
        $this->assertSame('invalid_api_key', $claim->recognition_failure_code);
        $this->assertNull($claim->recognition_result);
        $audit = AuditLog::query()->where('action', 'student_discount_claim_queued_for_manual_review')->sole();
        $this->assertSame('invalid_api_key', data_get($audit->metadata, 'recognition_status'));
        $this->assertStringNotContainsString('private-provider-detail', $audit->toJson());
        $this->assertStringNotContainsString('synthetic-invalid-key', $audit->toJson());

        $this->actingAs($admin)
            ->get(route('student-discounts.index', [$organization, $store]).'?tab=claims')
            ->assertOk()
            ->assertDontSee('private-provider-detail')
            ->assertInertia(fn (Assert $page) => $page
                ->where('claims.data.0.id', $claim->uuid)
                ->where('claims.data.0.recognition_failure_code', 'invalid_api_key')
                ->where('claims.data.0.recognition_result', null));
    }

    public function test_unrelated_image_is_queued_for_manual_review_without_auto_rejection(): void
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
                    'confidence' => 5,
                    'review_notes' => 'Unrelated image without a student card layout.',
                ], JSON_THROW_ON_ERROR)]]]]],
            ]),
        ]);

        $url = $this->signedProxyUrl(route('student-discounts.public.claims.store'), 'student-test.myshopify.com');
        $this->post($url, [
            'name' => 'Student Example',
            'email' => 'student@example.com',
            'privacy_consent' => true,
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

    public function test_new_submission_voids_prior_pending_claim_deletes_old_evidence_and_preserves_scoped_history(): void
    {
        Storage::fake('local');
        Mail::fake();
        Queue::fake();
        [$admin, $organization, $store] = $this->context('store-admin');
        $this->campaign($organization, $store, ['enabled' => true]);
        $this->setting('student_ai', 'gemini_api_key', 'test-gemini-key', true, null);
        $this->setting('student_ai', 'gemini_model', 'gemini-2.5-pro', false, null);
        $this->setting('student_ai', 'auto_approval_threshold', 80, false, null);
        config(['student_discount.active.client_secret' => 'proxy-shared-secret']);

        $oldPath = "student-discounts/{$organization->id}/{$store->id}/old-pending/student-id.jpg";
        Storage::disk('local')->put($oldPath, 'private-old-evidence');
        $oldClaim = $this->claim($organization, $store, 'old-pending', [
            'name' => 'Previous Student',
            'email' => 'resubmit@example.com',
            'normalized_email' => 'resubmit@example.com',
            'privacy_consented_at' => now()->subDay(),
            'evidence_disk' => 'local',
            'evidence_path' => $oldPath,
            'evidence_mime' => 'image/jpeg',
            'evidence_size' => 20,
            'submission_count' => 1,
        ]);
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'is_student_id' => false,
                    'institution_name' => null,
                    'confidence' => 10,
                    'review_notes' => 'The image does not show a recognizable student card layout.',
                ], JSON_THROW_ON_ERROR)]]]]],
            ]),
        ]);

        $response = $this->post($this->signedProxyUrl(route('student-discounts.public.claims.store'), $store->shopify_domain), [
            'name' => 'Updated Student',
            'email' => 'RESUBMIT@example.com',
            'privacy_consent' => true,
            'idempotency_key' => 'resubmit-new-claim-001',
            'evidence' => UploadedFile::fake()->create('new-student-id.jpg', 64, 'image/jpeg'),
        ], ['Accept' => 'application/json']);

        $response->assertAccepted()->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseCount('student_discount_claims', 2);
        $newClaim = StudentDiscountClaim::query()->where('id', '!=', $oldClaim->id)->sole();
        $oldClaim->refresh();

        $this->assertSame('voided', $oldClaim->status);
        $this->assertSame($newClaim->id, $oldClaim->superseded_by_claim_id);
        $this->assertNotNull($oldClaim->superseded_at);
        $this->assertNull($oldClaim->evidence_path);
        $this->assertNotNull($oldClaim->evidence_deleted_at);
        Storage::disk('local')->assertMissing($oldPath);
        $this->assertSame('Updated Student', $newClaim->name);
        $this->assertSame('resubmit@example.com', $newClaim->normalized_email);
        $this->assertNotNull($newClaim->privacy_consented_at);
        $this->assertFalse(array_key_exists('privacy_consent', $newClaim->getAttributes()));
        $this->assertSame(2, $newClaim->submission_count);
        Storage::disk('local')->assertExists((string) $newClaim->evidence_path);

        $voidedAudit = AuditLog::query()
            ->where('action', 'student_discount_claim_voided')
            ->where('subject_id', $oldClaim->id)
            ->sole();
        $this->assertSame('superseded', data_get($voidedAudit->metadata, 'reason'));
        $this->assertSame($newClaim->uuid, data_get($voidedAudit->metadata, 'superseded_by_claim_uuid'));
        $this->assertSame('pending', data_get($voidedAudit->metadata, 'evidence_cleanup_status'));
        $submittedAudit = AuditLog::query()
            ->where('action', 'student_discount_claim_submitted')
            ->where('subject_id', $newClaim->id)
            ->sole();
        $this->assertSame([$oldClaim->uuid], data_get($submittedAudit->metadata, 'superseded_claim_uuids'));
        $deletion = StudentDiscountEvidenceDeletion::query()->sole();
        $this->assertSame('completed', $deletion->status);
        $this->assertSame(1, $deletion->attempts);
        $this->assertNotNull($deletion->completed_at);
        $rawDeletionPath = (string) DB::table('student_discount_evidence_deletions')->where('id', $deletion->id)->value('path');
        $this->assertStringNotContainsString($oldPath, $rawDeletionPath);
        $deletedAudit = AuditLog::query()
            ->where('action', 'student_discount_claim_evidence_deleted')
            ->where('subject_id', $oldClaim->id)
            ->sole();
        $this->assertTrue((bool) data_get($deletedAudit->metadata, 'evidence_deleted'));
        $this->assertSame($deletion->uuid, data_get($deletedAudit->metadata, 'cleanup_uuid'));
        $auditPayload = AuditLog::query()->whereIn('id', [$voidedAudit->id, $submittedAudit->id, $deletedAudit->id])->get()->toJson();
        $this->assertStringNotContainsString('resubmit@example.com', strtolower($auditPayload));
        $this->assertStringNotContainsString('private-old-evidence', $auditPayload);

        $this->actingAs($admin)
            ->get(route('student-discounts.index', [$organization, $store]).'?status=voided')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('claims.data', 1)
                ->where('claims.data.0.id', $oldClaim->uuid)
                ->where('claims.data.0.name', 'Previous Student')
                ->where('claims.data.0.status', 'voided')
                ->where('claims.data.0.has_evidence', false)
                ->where('filterOptions.statuses.3.value', 'voided')
                ->where('filterOptions.statuses.3.label', '已作废'));

        $batchResponse = $this->actingAs($admin)->post(
            route('student-discounts.claims.bulk-approve', [$organization, $store]),
            ['claim_ids' => [$oldClaim->uuid]],
        );
        $batchResponse->assertRedirect()->assertSessionHas('student_discount_batch_result', function (array $result): bool {
            return data_get($result, 'items.0.code') === 'CLAIM_NOT_PENDING'
                && data_get($result, 'failed') === 1;
        });
        $this->assertSame('voided', $oldClaim->fresh()->status);
        $this->assertDatabaseMissing('student_discount_codes', ['claim_id' => $oldClaim->id]);
        Queue::assertNothingPushed();
    }

    public function test_submission_transaction_failure_preserves_previous_pending_record_and_evidence(): void
    {
        Storage::fake('local');
        Queue::fake();
        [, $organization, $store] = $this->context('store-admin');
        $this->campaign($organization, $store, ['enabled' => true]);
        $oldPath = "student-discounts/{$organization->id}/{$store->id}/rollback-old/student-id.jpg";
        Storage::disk('local')->put($oldPath, 'rollback-old-evidence');
        $oldClaim = $this->claim($organization, $store, 'rollback-old', [
            'name' => 'Rollback Student',
            'email' => 'rollback@example.com',
            'normalized_email' => 'rollback@example.com',
            'evidence_disk' => 'local',
            'evidence_path' => $oldPath,
            'evidence_mime' => 'image/jpeg',
            'evidence_size' => 21,
        ]);
        AuditLog::creating(function (AuditLog $audit): void {
            if ($audit->action === 'student_discount_claim_submitted') {
                throw new \RuntimeException('Synthetic transaction failure.');
            }
        });

        try {
            app(StudentDiscountClaimService::class)->submit(
                $store,
                'Replacement Student',
                'rollback@example.com',
                UploadedFile::fake()->create('replacement.jpg', 64, 'image/jpeg'),
                'rollback-new-claim-001',
                true,
            );
            $this->fail('The synthetic audit failure must roll back the submission transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic transaction failure.', $exception->getMessage());
        }

        $oldClaim->refresh();
        $this->assertSame('pending', $oldClaim->status);
        $this->assertNull($oldClaim->superseded_by_claim_id);
        $this->assertNull($oldClaim->superseded_at);
        $this->assertSame($oldPath, $oldClaim->evidence_path);
        $this->assertNull($oldClaim->evidence_deleted_at);
        Storage::disk('local')->assertExists($oldPath);
        $this->assertSame([$oldPath], Storage::disk('local')->allFiles("student-discounts/{$organization->id}/{$store->id}"));
        $this->assertDatabaseCount('student_discount_claims', 1);
        $this->assertDatabaseCount('student_discount_evidence_deletions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        Queue::assertNothingPushed();
    }

    public function test_post_commit_cleanup_failure_keeps_voided_state_and_queues_safe_retry(): void
    {
        Storage::fake('local');
        Queue::fake();
        [, $organization, $store] = $this->context('store-admin');
        $this->campaign($organization, $store, ['enabled' => true]);
        $this->setting('student_ai', 'gemini_api_key', 'test-gemini-key', true, null);
        $this->setting('student_ai', 'gemini_model', 'gemini-2.5-pro', false, null);
        $this->setting('student_ai', 'auto_approval_threshold', 80, false, null);
        $oldClaim = $this->claim($organization, $store, 'post-commit-failure', [
            'name' => 'Previous Student',
            'email' => 'post-commit@example.com',
            'normalized_email' => 'post-commit@example.com',
            'evidence_disk' => 'missing-evidence-disk',
            'evidence_path' => 'private/old-student-id.jpg',
            'evidence_mime' => 'image/jpeg',
            'evidence_size' => 32,
        ]);
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'is_student_id' => false,
                    'institution_name' => null,
                    'confidence' => 10,
                    'review_notes' => 'The image does not show a recognizable student card layout.',
                ], JSON_THROW_ON_ERROR)]]]]],
            ]),
        ]);

        $result = app(StudentDiscountClaimService::class)->submit(
            $store,
            'Replacement Student',
            'post-commit@example.com',
            UploadedFile::fake()->create('replacement.jpg', 64, 'image/jpeg'),
            'post-commit-new-001',
            true,
        );

        $this->assertSame('pending', $result['claim']->status);
        $oldClaim->refresh();
        $this->assertSame('voided', $oldClaim->status);
        $this->assertNull($oldClaim->evidence_path);
        $this->assertNull($oldClaim->evidence_disk);
        $this->assertNull($oldClaim->evidence_deleted_at);
        $deletion = StudentDiscountEvidenceDeletion::query()->sole();
        $this->assertSame('failed', $deletion->status);
        $this->assertSame(1, $deletion->attempts);
        $this->assertSame('storage_delete_failed', $deletion->last_error_code);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_discount_claim_evidence_deleted']);
        Queue::assertPushed(
            DeleteSupersededStudentDiscountEvidence::class,
            fn (DeleteSupersededStudentDiscountEvidence $job): bool => $job->deletionId === $deletion->id,
        );
    }

    public function test_evidence_cleanup_failure_is_sanitized_retryable_and_marks_deletion_only_after_success(): void
    {
        Storage::fake('local');
        [, $organization, $store] = $this->context('store-admin');
        $claim = $this->claim($organization, $store, 'cleanup-retry', [
            'status' => 'voided',
            'evidence_disk' => null,
            'evidence_path' => null,
            'evidence_mime' => null,
            'evidence_size' => null,
            'evidence_deleted_at' => null,
            'superseded_at' => now(),
        ]);
        $sensitivePath = "student-discounts/{$organization->id}/{$store->id}/private/student-id.jpg";
        $deletion = StudentDiscountEvidenceDeletion::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'claim_id' => $claim->id,
            'disk' => 'missing-evidence-disk',
            'path' => $sensitivePath,
            'status' => 'pending',
        ]);
        $cleanup = app(StudentDiscountEvidenceCleanupService::class);
        $job = new DeleteSupersededStudentDiscountEvidence($deletion->id);

        try {
            $job->handle($cleanup);
            $this->fail('An unavailable evidence disk must fail safely.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Student discount evidence cleanup failed.', $exception->getMessage());
            $this->assertStringNotContainsString($sensitivePath, $exception->getMessage());
        }

        $deletion->refresh();
        $this->assertSame('failed', $deletion->status);
        $this->assertSame(1, $deletion->attempts);
        $this->assertSame('storage_delete_failed', $deletion->last_error_code);
        $this->assertNull($deletion->completed_at);
        $this->assertNull($claim->fresh()->evidence_deleted_at);
        $rawPath = (string) DB::table('student_discount_evidence_deletions')->where('id', $deletion->id)->value('path');
        $this->assertStringNotContainsString($sensitivePath, $rawPath);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_discount_claim_evidence_deleted']);

        $job->failed(null);
        $failedAudit = AuditLog::query()
            ->where('action', 'student_discount_claim_evidence_cleanup_failed')
            ->where('subject_id', $claim->id)
            ->sole();
        $failedAuditPayload = $failedAudit->toJson();
        $this->assertSame('storage_delete_failed', data_get($failedAudit->metadata, 'error_code'));
        $this->assertStringNotContainsString($sensitivePath, $failedAuditPayload);

        $retryPath = "student-discounts/{$organization->id}/{$store->id}/retry/student-id.jpg";
        Storage::disk('local')->put($retryPath, 'retry-private-evidence');
        $deletion->forceFill([
            'disk' => 'local',
            'path' => $retryPath,
            'status' => 'failed',
        ])->save();
        $job->handle($cleanup);

        $deletion->refresh();
        $this->assertSame('completed', $deletion->status);
        $this->assertSame(2, $deletion->attempts);
        $this->assertNull($deletion->last_error_code);
        $this->assertNotNull($deletion->completed_at);
        $this->assertNotNull($claim->fresh()->evidence_deleted_at);
        Storage::disk('local')->assertMissing($retryPath);
        $deletedAudit = AuditLog::query()
            ->where('action', 'student_discount_claim_evidence_deleted')
            ->where('subject_id', $claim->id)
            ->sole();
        $this->assertTrue((bool) data_get($deletedAudit->metadata, 'evidence_deleted'));
        $this->assertStringNotContainsString($retryPath, $deletedAudit->toJson());
    }

    public function test_idempotency_rejects_different_evidence_with_the_same_size_and_mime(): void
    {
        Storage::fake('local');
        [, $organization, $store] = $this->context('store-admin');
        $this->campaign($organization, $store, ['enabled' => true]);
        $claims = app(StudentDiscountClaimService::class);

        $claims->submit(
            $store,
            'Student Example',
            'student@example.com',
            UploadedFile::fake()->createWithContent('first.webp', str_repeat('A', 128)),
            'claim-content-hash-001',
            true,
        );

        try {
            $claims->submit(
                $store,
                'Student Example',
                'student@example.com',
                UploadedFile::fake()->createWithContent('second.webp', str_repeat('B', 128)),
                'claim-content-hash-001',
                true,
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
            ->where('permissions.deleteClaim', false)
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

    public function test_store_admin_can_soft_delete_current_store_claim_and_evidence_idempotently_without_cross_store_deletion(): void
    {
        Storage::fake('local');
        [$admin, $organization, $store] = $this->context('store-admin');
        $path = "student-discounts/{$organization->id}/{$store->id}/delete-test/student-id.jpg";
        Storage::disk('local')->put($path, 'private-student-evidence');
        $connection = $this->connection($store);
        $this->studentInstallation($store, $connection);
        Http::fake(function ($request) {
            if (str_contains((string) ($request['query'] ?? ''), 'discountCodeDelete')) {
                return Http::response(['data' => ['discountCodeDelete' => [
                    'deletedCodeDiscountId' => 'gid://shopify/DiscountCodeNode/delete-preserved',
                    'userErrors' => [],
                ]]]);
            }

            return Http::response(['data' => ['codeDiscountNodeByCode' => [
                'id' => 'gid://shopify/DiscountCodeNode/delete-preserved',
                'codeDiscount' => ['codes' => ['nodes' => [['asyncUsageCount' => 0]]]],
            ]]]);
        });
        $claim = StudentDiscountClaim::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'email' => 'delete-student@example.com',
            'normalized_email' => 'delete-student@example.com',
            'source' => 'student_id',
            'status' => 'approved',
            'evidence_disk' => 'local',
            'evidence_path' => $path,
            'evidence_mime' => 'image/jpeg',
            'evidence_size' => 24,
            'idempotency_key' => 'delete-claim-001',
            'request_fingerprint' => hash('sha256', 'delete-claim-001'),
            'claim_token_hash' => hash('sha256', 'delete-claim-token'),
            'claim_token_encrypted' => 'delete-claim-token',
        ]);
        $code = StudentDiscountCode::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'claim_id' => $claim->id,
            'normalized_email' => $claim->normalized_email,
            'shopify_discount_id' => 'gid://shopify/DiscountCodeNode/delete-preserved',
            'code' => 'STUDENT-DELETE-PRESERVED',
            'status' => 'unused',
            'usage_count' => 0,
            'usage_limit' => 1,
            'idempotency_key' => 'claim:'.$claim->uuid,
            'generated_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
        DB::table('student_discount_claim_idempotencies')->insert([
            'store_id' => $store->id,
            'claim_id' => $claim->id,
            'idempotency_key' => 'delete-claim-001',
            'request_fingerprint' => hash('sha256', 'delete-claim-001'),
            'created_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('student-discounts.index', [$organization, $store]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('permissions.deleteClaim', true));

        $deleteUrl = route('student-discounts.claims.destroy', [$organization, $store, 'claim' => $claim->uuid]);
        $this->actingAs($admin)->delete($deleteUrl)->assertRedirect();

        Storage::disk('local')->assertMissing($path);
        $this->assertSoftDeleted('student_discount_claims', ['id' => $claim->id]);
        $deleted = StudentDiscountClaim::withTrashed()->findOrFail($claim->id);
        $this->assertNull($deleted->evidence_path);
        $this->assertNotNull($deleted->evidence_deleted_at);
        $this->assertNull($deleted->idempotency_key);
        $this->assertDatabaseMissing('student_discount_codes', ['id' => $code->id]);
        $this->assertDatabaseMissing('student_discount_claim_idempotencies', ['claim_id' => $claim->id]);
        $audit = AuditLog::query()->where('action', 'student_discount_claim_deleted')->sole();
        $this->assertSame($organization->id, $audit->organization_id);
        $this->assertSame($store->id, $audit->store_id);
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame($claim->id, $audit->subject_id);
        $this->assertTrue((bool) data_get($audit->metadata, 'evidence_deleted'));
        $this->assertTrue((bool) data_get($audit->metadata, 'discount_code_deleted'));
        $auditPayload = json_encode($audit->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('delete-student@example.com', $auditPayload);
        $this->assertStringNotContainsString('private-student-evidence', $auditPayload);

        $this->actingAs($admin)->delete($deleteUrl)->assertRedirect();
        $this->assertSame(1, AuditLog::query()->where('action', 'student_discount_claim_deleted')->count());
        Http::assertSent(fn ($request): bool => str_contains((string) ($request['query'] ?? ''), 'discountCodeDelete'));
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_contains((string) ($request['query'] ?? ''), 'discountCodeDelete')));

        $otherStore = $organization->stores()->create([
            'name' => 'Other Student Delete Store',
            'shopify_domain' => 'other-student-delete.myshopify.com',
            'status' => 'active',
        ]);
        $otherClaim = StudentDiscountClaim::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $otherStore->id,
            'email' => 'other-delete@example.com',
            'normalized_email' => 'other-delete@example.com',
            'source' => 'student_id',
            'status' => 'pending',
            'claim_token_hash' => hash('sha256', 'other-delete-token'),
            'claim_token_encrypted' => 'other-delete-token',
        ]);
        $this->actingAs($admin)
            ->delete(route('student-discounts.claims.destroy', [$organization, $store, 'claim' => $otherClaim->uuid]))
            ->assertRedirect();
        $this->assertDatabaseHas('student_discount_claims', ['id' => $otherClaim->id, 'deleted_at' => null]);
    }

    public function test_operator_without_delete_permission_cannot_delete_claim_or_evidence(): void
    {
        Storage::fake('local');
        [$operator, $organization, $store] = $this->context('operator');
        $path = "student-discounts/{$organization->id}/{$store->id}/forbidden-delete/student-id.jpg";
        Storage::disk('local')->put($path, 'private-student-evidence');
        $claim = StudentDiscountClaim::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'email' => 'forbidden-delete@example.com',
            'normalized_email' => 'forbidden-delete@example.com',
            'source' => 'student_id',
            'status' => 'pending',
            'evidence_disk' => 'local',
            'evidence_path' => $path,
            'evidence_mime' => 'image/jpeg',
            'evidence_size' => 24,
            'claim_token_hash' => hash('sha256', 'forbidden-delete-token'),
            'claim_token_encrypted' => 'forbidden-delete-token',
        ]);

        $this->actingAs($operator)
            ->delete(route('student-discounts.claims.destroy', [$organization, $store, 'claim' => $claim->uuid]))
            ->assertForbidden();

        Storage::disk('local')->assertExists($path);
        $this->assertDatabaseHas('student_discount_claims', ['id' => $claim->id, 'deleted_at' => null]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'student_discount_claim_deleted']);
    }

    public function test_claim_list_combines_filters_usage_states_and_store_scope_without_n_plus_one_syncs(): void
    {
        [$admin, $organization, $store] = $this->context('store-admin');
        $otherStore = $organization->stores()->create([
            'name' => 'Other Filter Store',
            'shopify_domain' => 'other-filter-store.myshopify.com',
            'status' => 'active',
            'timezone' => 'UTC',
        ]);
        $matching = $this->claim($organization, $store, 'target-manual', [
            'email' => 'target.manual@example.edu',
            'normalized_email' => 'target.manual@example.edu',
            'source' => 'student_id',
            'status' => 'approved',
            'review_method' => 'manual',
        ]);
        DB::table('student_discount_claims')->where('id', $matching->id)->update(['created_at' => '2026-08-15 12:00:00']);
        $this->discountCode($matching, 'partial', ['usage_count' => 1, 'usage_limit' => 3, 'expires_at' => '2026-09-15 12:00:00']);

        $unused = $this->claim($organization, $store, 'unused', ['status' => 'approved', 'source' => 'education_email', 'review_method' => 'education_email']);
        $this->discountCode($unused, 'unused', ['usage_count' => 0, 'usage_limit' => 2, 'expires_at' => '2026-09-15 12:00:00']);
        $usedUp = $this->claim($organization, $store, 'used-up', ['status' => 'approved', 'review_method' => 'ai']);
        $this->discountCode($usedUp, 'used-up', ['usage_count' => 2, 'usage_limit' => 2, 'expires_at' => '2026-09-15 12:00:00']);
        $expired = $this->claim($organization, $store, 'expired', ['status' => 'approved', 'review_method' => 'manual']);
        $this->discountCode($expired, 'expired', ['usage_count' => 0, 'usage_limit' => 1, 'expires_at' => '2026-07-15 12:00:00']);
        $otherClaim = $this->claim($organization, $otherStore, 'other-target', [
            'email' => 'target.manual.other@example.edu',
            'normalized_email' => 'target.manual.other@example.edu',
            'status' => 'approved',
            'review_method' => 'manual',
        ]);
        DB::table('student_discount_claims')->where('id', $otherClaim->id)->update(['created_at' => '2026-08-15 12:00:00']);
        $this->discountCode($otherClaim, 'other-partial', ['usage_count' => 1, 'usage_limit' => 3, 'expires_at' => '2026-09-15 12:00:00']);

        $filters = [
            'tab' => 'claims',
            'status' => 'approved',
            'source' => 'student_id',
            'review_method' => 'manual',
            'email' => 'TARGET.MANUAL',
            'submitted_from' => '2026-08-10',
            'submitted_to' => '2026-08-20',
            'usage_status' => 'partially_used',
        ];
        $this->actingAs($admin)
            ->get(route('student-discounts.index', [$organization, $store]).'?'.http_build_query($filters))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.status', 'approved')
                ->where('filters.email', 'target.manual')
                ->where('claims.total', 1)
                ->where('claims.data.0.id', $matching->uuid)
                ->where('claims.data.0.discount.status', 'partially_used')
                ->where('claims.data.0.discount.usage_count', 1)
                ->where('claims.data.0.discount.usage_limit', 3)
                ->has('filterOptions.sources', 2)
                ->has('filterOptions.review_methods', 4)
                ->has('filterOptions.usage_statuses', 4));

        foreach ([
            'unused' => $unused->uuid,
            'partially_used' => $matching->uuid,
            'used_up' => $usedUp->uuid,
            'expired' => $expired->uuid,
        ] as $usageStatus => $claimUuid) {
            $this->actingAs($admin)
                ->get(route('student-discounts.index', [$organization, $store]).'?'.http_build_query(['tab' => 'claims', 'usage_status' => $usageStatus]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('claims.total', 1)
                    ->where('claims.data.0.id', $claimUuid)
                    ->where('claims.data.0.discount.status', $usageStatus));
        }
    }

    public function test_usage_sync_is_bounded_store_scoped_and_preserves_cached_usage_on_shopify_failure(): void
    {
        Queue::fake();
        [$operator, $organization, $store] = $this->context('operator');
        $connection = $this->connection($store);
        $this->studentInstallation($store, $connection);
        $claim = $this->claim($organization, $store, 'usage-sync', ['status' => 'approved']);
        $code = $this->discountCode($claim, 'usage-sync', ['usage_count' => 1, 'usage_limit' => 3]);
        $otherStore = $organization->stores()->create([
            'name' => 'Other Usage Store',
            'shopify_domain' => 'other-usage-store.myshopify.com',
            'status' => 'active',
        ]);
        $otherClaim = $this->claim($organization, $otherStore, 'other-usage', ['status' => 'approved']);
        $otherCode = $this->discountCode($otherClaim, 'other-usage');

        $this->actingAs($operator)
            ->post(route('student-discounts.claims.usage-sync', [$organization, $store]), ['code_ids' => [$code->uuid, $otherCode->uuid]])
            ->assertRedirect();
        Queue::assertPushed(SyncStudentDiscountCodeUsage::class, fn (SyncStudentDiscountCodeUsage $job): bool => $job->organizationId === $organization->id
            && $job->storeId === $store->id
            && $job->codeId === $code->id);
        Queue::assertPushed(SyncStudentDiscountCodeUsage::class, 1);

        $this->actingAs($operator)
            ->post(route('student-discounts.claims.usage-sync', [$organization, $store]), [
                'code_ids' => collect(range(1, 51))->map(fn (): string => (string) Str::uuid())->all(),
            ])
            ->assertSessionHasErrors('code_ids');

        Http::fake([
            "https://{$store->shopify_domain}/*" => Http::response(['data' => ['codeDiscountNodeByCode' => [
                'id' => 'gid://shopify/DiscountCodeNode/usage-sync',
                'codeDiscount' => ['codes' => ['nodes' => [['code' => $code->code, 'asyncUsageCount' => 2]]]],
            ]]]),
        ]);
        (new SyncStudentDiscountCodeUsage($organization->id, $store->id, $code->id))->handle(app(StudentDiscountCodeService::class));
        $this->assertSame(2, $code->fresh()->usage_count);
        $this->assertSame('partially_used', $code->fresh()->status);
        $this->assertNotNull($code->fresh()->last_synced_at);

        Http::fake(["https://{$store->shopify_domain}/*" => Http::response([], 503)]);
        (new SyncStudentDiscountCodeUsage($organization->id, $store->id, $code->id))->handle(app(StudentDiscountCodeService::class));
        $this->assertSame(2, $code->fresh()->usage_count);
        $this->assertSame('partially_used', $code->fresh()->status);
    }

    public function test_bulk_reject_reports_partial_results_enforces_limit_reason_status_and_store_scope_idempotently(): void
    {
        Queue::fake();
        [$operator, $organization, $store] = $this->context('operator');
        $pendingOne = $this->claim($organization, $store, 'reject-one');
        $pendingTwo = $this->claim($organization, $store, 'reject-two');
        $alreadyRejected = $this->claim($organization, $store, 'already-rejected', ['status' => 'rejected', 'review_method' => 'manual']);
        $approved = $this->claim($organization, $store, 'approved-reject', ['status' => 'approved']);
        $otherStore = $organization->stores()->create([
            'name' => 'Other Reject Store',
            'shopify_domain' => 'other-reject-store.myshopify.com',
            'status' => 'active',
        ]);
        $otherPending = $this->claim($organization, $otherStore, 'other-reject');
        $ids = [$pendingOne->uuid, $pendingTwo->uuid, $alreadyRejected->uuid, $approved->uuid, $otherPending->uuid];

        $this->actingAs($operator)
            ->post(route('student-discounts.claims.bulk-reject', [$organization, $store]), ['claim_ids' => $ids])
            ->assertSessionHasErrors('reason');
        $this->actingAs($operator)
            ->post(route('student-discounts.claims.bulk-reject', [$organization, $store]), ['claim_ids' => $ids, 'reason' => '   '])
            ->assertSessionHasErrors('reason');
        $this->actingAs($operator)
            ->post(route('student-discounts.claims.bulk-reject', [$organization, $store]), ['claim_ids' => $ids, 'reason' => '统一验证失败'])
            ->assertRedirect()
            ->assertSessionHas('student_discount_batch_result', fn (array $result): bool => $result['succeeded'] === 2
                && $result['unchanged'] === 1
                && $result['failed'] === 2);

        $this->assertSame('rejected', $pendingOne->fresh()->status);
        $this->assertSame('统一验证失败', $pendingOne->fresh()->rejection_reason);
        $this->assertSame('rejected', $pendingTwo->fresh()->status);
        $this->assertSame('approved', $approved->fresh()->status);
        $this->assertSame('pending', $otherPending->fresh()->status);
        Queue::assertPushed(SendStudentDiscountDecisionMail::class, 2);
        $this->assertStringNotContainsString('统一验证失败', AuditLog::query()->where('action', 'student_discount_claim_rejected')->get()->toJson());

        $this->actingAs($operator)
            ->post(route('student-discounts.claims.bulk-reject', [$organization, $store]), ['claim_ids' => [$pendingOne->uuid, $pendingTwo->uuid], 'reason' => '重复操作'])
            ->assertSessionHas('student_discount_batch_result', fn (array $result): bool => $result['succeeded'] === 0
                && $result['unchanged'] === 2
                && $result['failed'] === 0);
        Queue::assertPushed(SendStudentDiscountDecisionMail::class, 2);

        $this->actingAs($operator)
            ->post(route('student-discounts.claims.bulk-reject', [$organization, $store]), [
                'claim_ids' => collect(range(1, 31))->map(fn (): string => (string) Str::uuid())->all(),
                'reason' => '批量上限',
            ])
            ->assertSessionHasErrors('claim_ids');
    }

    public function test_bulk_approve_reuses_claim_service_and_blocks_unauthorized_or_cross_store_items(): void
    {
        Queue::fake();
        [$operator, $organization, $store] = $this->context('operator');
        $campaign = $this->campaign($organization, $store, ['enabled' => true]);
        $connection = $this->connection($store);
        $this->studentInstallation($store, $connection);
        $pendingOne = $this->claim($organization, $store, 'approve-one');
        $pendingTwo = $this->claim($organization, $store, 'approve-two');
        $alreadyApproved = $this->claim($organization, $store, 'already-approved', ['status' => 'approved', 'review_method' => 'manual']);
        $this->discountCode($alreadyApproved, 'already-approved');
        $rejected = $this->claim($organization, $store, 'rejected-approve', ['status' => 'rejected']);
        $otherStore = $organization->stores()->create([
            'name' => 'Other Approve Store',
            'shopify_domain' => 'other-approve-store.myshopify.com',
            'status' => 'active',
        ]);
        $otherPending = $this->claim($organization, $otherStore, 'other-approve');
        Http::fake(function ($request) {
            if (str_contains((string) ($request['query'] ?? ''), 'codeDiscountNodeByCode')) {
                return Http::response(['data' => ['codeDiscountNodeByCode' => null]]);
            }

            return Http::response(['data' => ['discountCodeBasicCreate' => [
                'codeDiscountNode' => ['id' => 'gid://shopify/DiscountCodeNode/batch-created'],
                'userErrors' => [],
            ]]]);
        });

        $ids = [$pendingOne->uuid, $pendingTwo->uuid, $alreadyApproved->uuid, $rejected->uuid, $otherPending->uuid];
        $this->actingAs($operator)
            ->post(route('student-discounts.claims.bulk-approve', [$organization, $store]), ['claim_ids' => $ids])
            ->assertRedirect()
            ->assertSessionHas('student_discount_batch_result', fn (array $result): bool => $result['succeeded'] === 2
                && $result['unchanged'] === 1
                && $result['failed'] === 2);

        $this->assertSame('approved', $pendingOne->fresh()->status);
        $this->assertSame('approved', $pendingTwo->fresh()->status);
        $this->assertSame('rejected', $rejected->fresh()->status);
        $this->assertSame('pending', $otherPending->fresh()->status);
        $this->assertSame($campaign->usage_limit, $pendingOne->fresh()->discountCode->usage_limit);
        Queue::assertPushed(SendStudentDiscountDecisionMail::class, 2);

        $this->actingAs($operator)
            ->post(route('student-discounts.claims.bulk-approve', [$organization, $store]), ['claim_ids' => [$pendingOne->uuid, $pendingTwo->uuid]])
            ->assertSessionHas('student_discount_batch_result', fn (array $result): bool => $result['succeeded'] === 0
                && $result['unchanged'] === 2
                && $result['failed'] === 0);
        Queue::assertPushed(SendStudentDiscountDecisionMail::class, 2);

        $viewer = User::factory()->create();
        $organization->users()->attach($viewer, ['status' => 'active', 'joined_at' => now()]);
        $store->members()->attach($viewer, ['status' => 'active', 'joined_at' => now()]);
        $viewerRole = Role::query()->whereBelongsTo($organization)->where('slug', 'viewer')->firstOrFail();
        $viewer->roles()->attach($viewerRole, ['organization_id' => $organization->id, 'store_id' => null]);
        $viewerClaim = $this->claim($organization, $store, 'viewer-approve');
        $this->actingAs($viewer)
            ->post(route('student-discounts.claims.bulk-approve', [$organization, $store]), ['claim_ids' => [$viewerClaim->uuid]])
            ->assertForbidden();
    }

    public function test_decision_mail_job_marks_claim_and_code_sent_once_without_serializing_pii(): void
    {
        Mail::fake();
        $this->configureDeliveringMailTransport();
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

    public function test_store_email_templates_are_isolated_editable_and_audited_without_raw_body_content(): void
    {
        [$admin, $organization, $store] = $this->context('store-admin');
        $campaign = $this->campaign($organization, $store);
        $otherStore = $organization->stores()->create([
            'name' => 'Other Store',
            'shopify_domain' => 'other-template-store.myshopify.com',
            'status' => 'active',
        ]);
        $otherCampaign = $this->campaign($organization, $otherStore);
        $templates = [
            'approval' => [
                'subject' => '{{ store_name }} approved',
                'body' => 'Code {{ discount_code }} for {{ applicant_email }} expires {{ expires_at }}.',
            ],
            'rejection' => [
                'subject' => '{{ store_name }} request update',
                'body' => 'We could not approve this request: {{ rejection_reason }}',
            ],
        ];

        $this->actingAs($admin)
            ->get(route('student-discounts.index', [$organization, $store]).'?tab=emails')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.manageEmailTemplates', true)
                ->where('emailTemplates.templates.approval.subject', 'Your {{ store_name }} student discount code')
                ->where('emailTemplates.branding.shop_url', 'https://'.$store->shopify_domain)
                ->where('emailTemplates.variables.0.sample', $store->name)
                ->has('emailTemplates.variables', 6));

        $this->actingAs($admin)
            ->put(route('student-discounts.email-templates.update', [$organization, $store]), $templates)
            ->assertRedirect();

        $stored = $campaign->fresh()->email_templates;
        $this->assertSame($templates['approval']['subject'], data_get($stored, 'templates.approval.subject'));
        $this->assertSame($templates['approval']['body'], data_get($stored, 'templates.approval.body'));
        $this->assertSame('https://'.$store->shopify_domain, data_get($stored, 'branding.shop_url'));
        $this->assertNull($otherCampaign->fresh()->email_templates);
        $audit = AuditLog::query()->where('action', 'student_discount_email_templates_updated')->sole();
        $this->assertSame($store->id, $audit->store_id);
        $this->assertStringNotContainsString($templates['approval']['body'], $audit->toJson());
        $this->assertNotNull(data_get($audit->new_values, 'templates.approval.content_sha256'));
        $this->assertTrue(data_get($audit->new_values, 'branding.shop_link_configured'));
    }

    public function test_email_template_variables_are_whitelisted_and_critical_variables_are_required(): void
    {
        [$admin, $organization, $store] = $this->context('store-admin');
        $this->campaign($organization, $store);
        $base = [
            'approval' => ['subject' => 'Approved', 'body' => 'Code {{ discount_code }}'],
            'rejection' => ['subject' => 'Rejected', 'body' => 'Reason {{ rejection_reason }}'],
        ];

        $invalidVariable = $base;
        $invalidVariable['approval']['subject'] = 'Approved {{ unsafe_html }}';
        $this->actingAs($admin)
            ->put(route('student-discounts.email-templates.update', [$organization, $store]), $invalidVariable)
            ->assertSessionHasErrors('approval.subject');

        $missingReason = $base;
        $missingReason['rejection']['body'] = 'Rejected without a reason.';
        $this->actingAs($admin)
            ->put(route('student-discounts.email-templates.update', [$organization, $store]), $missingReason)
            ->assertSessionHasErrors('rejection.body');

        $unsafeUrl = $base + ['branding' => ['shop_url' => 'javascript:alert(1)']];
        $this->actingAs($admin)
            ->put(route('student-discounts.email-templates.update', [$organization, $store]), $unsafeUrl)
            ->assertSessionHasErrors('branding.shop_url');
    }

    public function test_operator_cannot_manage_or_test_student_discount_email_templates(): void
    {
        [$operator, $organization, $store] = $this->context('operator');
        $this->campaign($organization, $store);
        $payload = [
            'approval' => ['subject' => 'Approved', 'body' => 'Code {{ discount_code }}'],
            'rejection' => ['subject' => 'Rejected', 'body' => 'Reason {{ rejection_reason }}'],
        ];

        $this->actingAs($operator)
            ->get(route('student-discounts.index', [$organization, $store]))
            ->assertInertia(fn (Assert $page) => $page->where('permissions.manageEmailTemplates', false));
        $this->actingAs($operator)
            ->put(route('student-discounts.email-templates.update', [$organization, $store]), $payload)
            ->assertForbidden();
        $this->actingAs($operator)
            ->post(route('student-discounts.email-templates.test', [$organization, $store]), [
                'type' => 'approval',
                'email' => 'recipient@example.com',
                'subject' => 'Approved',
                'body' => 'Code {{ discount_code }}',
            ])
            ->assertForbidden();
    }

    public function test_decision_and_test_emails_render_the_current_store_template(): void
    {
        Mail::fake();
        $this->configureDeliveringMailTransport();
        [$admin, $organization, $store] = $this->context('store-admin');
        $campaign = $this->campaign($organization, $store, [
            'email_templates' => [
                'approval' => [
                    'subject' => '{{ store_name }} approved {{ applicant_email }}',
                    'body' => 'Use {{ discount_code }} before {{ expires_at }}. Limit {{ usage_limit }}.',
                ],
                'rejection' => [
                    'subject' => '{{ store_name }} rejected',
                    'body' => 'Reason: {{ rejection_reason }}',
                ],
            ],
        ]);
        $claim = StudentDiscountClaim::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'email' => 'template-student@example.edu',
            'normalized_email' => 'template-student@example.edu',
            'source' => 'education_email',
            'status' => 'approved',
            'claim_token_hash' => hash('sha256', 'template-claim-token'),
            'claim_token_encrypted' => 'template-claim-token',
        ]);
        $code = StudentDiscountCode::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'claim_id' => $claim->id,
            'normalized_email' => $claim->normalized_email,
            'code' => 'STUDENT-TEMPLATE',
            'status' => 'unused',
            'usage_count' => 0,
            'usage_limit' => 2,
            'idempotency_key' => 'claim:'.$claim->uuid,
            'generated_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        (new SendStudentDiscountDecisionMail($organization->id, $store->id, $claim->id, $code->id))->handle();

        Mail::assertSent(StudentDiscountDecisionMail::class, function (StudentDiscountDecisionMail $mail) use ($store, $claim): bool {
            return $mail->renderedSubject === "{$store->name} approved {$claim->email}"
                && str_contains((string) $mail->renderedBody, 'STUDENT-TEMPLATE')
                && str_contains((string) $mail->renderedBody, 'Limit 2.')
                && str_contains($mail->render(), 'STUDENT-TEMPLATE')
                && str_contains($mail->render(), 'SHOP NOW');
        });

        $this->actingAs($admin)
            ->post(route('student-discounts.email-templates.test', [$organization, $store]), [
                'type' => 'rejection',
                'email' => 'preview-recipient@example.com',
                'branding' => ['primary_color' => '#111111', 'shop_url' => 'https://'.$store->shopify_domain],
                'approval' => ['subject' => 'Approved', 'body' => 'Approved'],
                'rejection' => ['subject' => '{{ store_name }} preview', 'body' => 'Preview reason: {{ rejection_reason }}'],
            ])
            ->assertRedirect();
        Mail::assertSent(StudentDiscountTemplatePreviewMail::class, function (StudentDiscountTemplatePreviewMail $mail) use ($store): bool {
            return $mail->hasTo('preview-recipient@example.com')
                && $mail->renderedSubject === "{$store->name} preview"
                && str_contains($mail->renderedBody, 'submitted image');
        });
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'student_discount_email_template_test_sent',
            'store_id' => $campaign->store_id,
        ]);
    }

    public function test_decision_mail_job_sends_outside_a_transaction_and_preserves_concurrent_success_markers(): void
    {
        $this->configureDeliveringMailTransport();
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
        $this->configureDeliveringMailTransport();
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

    public function test_decision_mail_job_never_marks_log_transport_as_sent(): void
    {
        Mail::fake();
        config(['mail.default' => 'log']);
        [, $organization, $store] = $this->context('store-admin');
        $claim = StudentDiscountClaim::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'email' => 'log-transport@example.edu',
            'normalized_email' => 'log-transport@example.edu',
            'source' => 'student_id',
            'status' => 'approved',
            'claim_token_hash' => hash('sha256', 'log-transport-token'),
            'claim_token_encrypted' => 'log-transport-token',
        ]);
        $job = new SendStudentDiscountDecisionMail($organization->id, $store->id, $claim->id, null);

        try {
            $job->handle();
            $this->fail('A non-delivering mail transport must fail safely.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Student discount decision email delivery failed.', $exception->getMessage());
            $this->assertStringNotContainsString($claim->email, $exception->getMessage());
            $job->failed($exception);
        }

        Mail::assertNothingSent();
        $this->assertNull($claim->fresh()->email_sent_at);
        $this->assertNotNull($claim->fresh()->email_failed_at);
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

    /** @param array<string, mixed> $overrides */
    private function claim(Organization $organization, Store $store, string $key, array $overrides = []): StudentDiscountClaim
    {
        return StudentDiscountClaim::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'name' => 'Test Student',
            'email' => "{$key}@example.com",
            'normalized_email' => "{$key}@example.com",
            'privacy_consented_at' => now(),
            'source' => 'student_id',
            'status' => 'pending',
            'claim_token_hash' => hash('sha256', "{$key}-token"),
            'claim_token_encrypted' => "{$key}-token",
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function discountCode(StudentDiscountClaim $claim, string $key, array $overrides = []): StudentDiscountCode
    {
        return StudentDiscountCode::query()->create([
            'organization_id' => $claim->organization_id,
            'store_id' => $claim->store_id,
            'claim_id' => $claim->id,
            'normalized_email' => $claim->normalized_email,
            'shopify_discount_id' => "gid://shopify/DiscountCodeNode/{$key}",
            'code' => 'STUDENT-'.strtoupper(str_replace('_', '-', $key)),
            'status' => 'unused',
            'usage_count' => 0,
            'usage_limit' => 1,
            'idempotency_key' => 'claim:'.$claim->uuid,
            'generated_at' => now(),
            'expires_at' => now()->addDays(7),
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

    private function configureDeliveringMailTransport(): void
    {
        config([
            'mail.default' => 'student-discount-test',
            'mail.mailers.student-discount-test' => [
                'transport' => 'smtp',
                'host' => 'smtp.test.invalid',
                'port' => 587,
            ],
            'mail.from.address' => 'no-reply@test.invalid',
            'mail.from.name' => 'DecoAdmin Test',
        ]);
    }

    /** @return array{current_organization_id: int, current_store_id: int} */
    private function contextSession(Organization $organization, Store $store): array
    {
        return ['current_organization_id' => $organization->id, 'current_store_id' => $store->id];
    }
}
