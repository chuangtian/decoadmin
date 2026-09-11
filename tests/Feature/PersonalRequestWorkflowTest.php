<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BusinessNotification;
use App\Models\Organization;
use App\Models\PersonalRequest;
use App\Models\PersonalRequestAttachment;
use App\Models\PersonalRequestPayment;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PersonalRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_technical_request_moves_from_employee_submission_through_approval_and_developer_completion(): void
    {
        CarbonImmutable::setTestNow('2026-09-11 10:00:00');
        Storage::fake('local');
        [$organization, $store] = $this->context();
        $employee = $this->user($organization, $store, 'viewer', '员工');
        $otherEmployee = $this->user($organization, $store, 'viewer', '另一员工');
        $approver = $this->user($organization, $store, 'super-admin', '超级管理员');
        $developer = $this->user($organization, $store, 'developer', '开发人员');

        $this->actingAs($employee)->withSession($this->contextSession($organization, $store))
            ->post(route('technical-requests.store'), [
                'title' => '订单导出增加筛选', 'category' => 'feature', 'priority' => 'high',
                'desired_date' => '2026-09-20', 'description' => '需要按国家筛选订单并导出。',
                'images' => [UploadedFile::fake()->image('requirement.png')],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $item = PersonalRequest::query()->sole();
        $attachment = PersonalRequestAttachment::query()->sole();
        $this->assertSame('technical', $item->kind);
        $this->assertSame('pending_approval', $item->status);
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertDatabaseHas('business_notifications', ['user_id' => $approver->id, 'type' => 'technical.submitted']);

        $this->get(route('technical-requests.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Requests/Technical')->where('scope', 'mine')->where('requests.total', 1));
        $this->actingAs($otherEmployee)->withSession($this->contextSession($organization, $store))
            ->get(route('technical-requests.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('requests.total', 0));

        $this->actingAs($approver)->withSession($this->contextSession($organization, $store))
            ->get(route('request-approvals.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Requests/Approvals')->where('requests.total', 1));
        $this->put(route('request-approvals.review', $item), ['action' => 'approve', 'note' => '范围清楚，同意开发'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('approved', $item->fresh()->status);
        $this->assertDatabaseHas('business_notifications', ['user_id' => $employee->id, 'type' => 'technical.reviewed']);
        $this->assertDatabaseHas('business_notifications', ['user_id' => $developer->id, 'type' => 'technical_request.approved']);

        $this->actingAs($developer)->withSession($this->contextSession($organization, $store))
            ->get(route('technical-requests.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('scope', 'all')->where('requests.data.0.can_accept', true));
        $this->post(route('technical-requests.accept', $item))->assertRedirect()->assertSessionHasNoErrors();
        $this->put(route('technical-requests.progress', $item), ['action' => 'progress', 'note' => '接口已完成'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->put(route('technical-requests.progress', $item), ['action' => 'complete', 'note' => '已上线测试'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('completed', $item->fresh()->status);
        $this->assertDatabaseCount('personal_request_progress_logs', 4);
        $this->assertSame(4, BusinessNotification::query()->where('user_id', $employee->id)->count());
    }

    public function test_expense_claim_requires_invoice_and_can_be_rejected_with_a_reason(): void
    {
        CarbonImmutable::setTestNow('2026-09-11 10:00:00');
        Storage::fake('local');
        [$organization, $store] = $this->context();
        $employee = $this->user($organization, $store, 'marketing', '报销员工');
        $approver = $this->user($organization, $store, 'super-admin', '超级管理员');

        $this->actingAs($employee)->withSession($this->contextSession($organization, $store))
            ->post(route('expense-claims.store'), [
                'title' => '设计软件订阅', 'category' => 'software', 'amount' => '299.00',
                'currency' => 'CNY', 'expense_date' => '2026-09-10', 'description' => '年度设计工具订阅。',
            ])->assertSessionHasErrors('images');
        $this->post(route('expense-claims.store'), [
            'title' => '设计软件订阅', 'category' => 'software', 'amount' => '299.00',
            'currency' => 'CNY', 'expense_date' => '2026-09-10', 'description' => '年度设计工具订阅。',
            'images' => [UploadedFile::fake()->image('invoice.jpg')],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $item = PersonalRequest::query()->sole();
        $this->assertSame('expense', $item->kind);
        $this->actingAs($approver)->withSession($this->contextSession($organization, $store))
            ->put(route('request-approvals.review', $item), ['action' => 'reject', 'note' => ''])
            ->assertSessionHasErrors(['note' => '驳回时必须填写审批意见。']);
        $this->actingAs($approver)->withSession($this->contextSession($organization, $store))
            ->put(route('request-approvals.review', $item), ['action' => 'reject', 'note' => '请补充付款凭证'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('rejected', $item->fresh()->status);
        $this->assertSame('请补充付款凭证', $item->fresh()->review_note);
        $this->assertDatabaseHas('business_notifications', ['user_id' => $employee->id, 'type' => 'expense.reviewed']);
    }

    public function test_cost_application_is_visible_to_submitter_and_only_super_admin_can_approve(): void
    {
        CarbonImmutable::setTestNow('2026-09-11 10:00:00');
        [$organization, $store] = $this->context();
        $employee = $this->user($organization, $store, 'operator', '申请员工');
        $otherEmployee = $this->user($organization, $store, 'marketing', '其他员工');
        $organizationAdmin = $this->user($organization, $store, 'organization-admin', '组织管理员');
        $superAdmin = $this->user($organization, $store, 'super-admin', '超级管理员');

        $this->actingAs($employee)->withSession($this->contextSession($organization, $store))
            ->post(route('expense-requests.store'), [
                'title' => '采购项目管理软件', 'category' => 'software', 'amount' => '1200.00',
                'currency' => 'CNY', 'desired_date' => '2026-09-20', 'description' => '用于跨部门项目排期。',
                'software_url' => 'https://software.example.com', 'software_account' => 'team@example.com',
                'software_password' => 'private-password', 'renewal_mode' => 'automatic',
                'billing_cycle' => 'annual',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $item = PersonalRequest::query()->sole();
        $this->assertSame('expense_request', $item->kind);
        $this->assertStringStartsWith('CR-', $item->reference_no);
        $this->assertSame('team@example.com', $item->software_account);
        $this->assertSame('private-password', $item->software_password);
        $raw = DB::table('personal_requests')->where('id', $item->id)->first();
        $this->assertNotSame('team@example.com', $raw->software_account);
        $this->assertNotSame('private-password', $raw->software_password);
        $this->get(route('expense-requests.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Requests/CostApplications')->where('scope', 'mine')->where('requests.total', 1));
        $this->actingAs($otherEmployee)->withSession($this->contextSession($organization, $store))
            ->get(route('expense-requests.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('scope', 'mine')->where('requests.total', 0));

        $this->actingAs($organizationAdmin)->withSession($this->contextSession($organization, $store))
            ->get(route('expense-requests.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('scope', 'mine')->where('requests.total', 0));
        $this->get(route('request-approvals.index'))->assertForbidden();

        $this->actingAs($superAdmin)->withSession($this->contextSession($organization, $store))
            ->get(route('expense-requests.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('scope', 'all')->where('requests.total', 1));
        $this->actingAs($superAdmin)->withSession($this->contextSession($organization, $store))
            ->get(route('request-approvals.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('requests.total', 1)
            ->where('requests.data.0.kind', 'expense_request')
            ->where('requests.data.0.software_account', 'team@example.com')
            ->where('requests.data.0.software_password_set', true)
            ->missing('requests.data.0.software_password'));
        $this->put(route('request-approvals.review', $item), ['action' => 'approve', 'note' => '预算合理'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('approved', $item->fresh()->status);
        $this->assertSame('pending', $item->fresh()->payment_status);
        $this->assertDatabaseHas('business_notifications', ['user_id' => $organizationAdmin->id, 'type' => 'expense_request.payment_required']);

        $this->actingAs($employee)->withSession($this->contextSession($organization, $store))
            ->post(route('finance.expense-requests.payment', $item), ['paid_on' => '2026-09-11'])
            ->assertForbidden();
        $this->getJson(route('finance.expense-requests.password', $item))->assertForbidden();

        $this->actingAs($organizationAdmin)->withSession($this->contextSession($organization, $store))
            ->get(route('finance.renewals'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Finance/Renewals')
            ->where('paymentRequests.0.reference_no', $item->reference_no)
            ->where('paymentRequests.0.payment_status', 'pending')
            ->where('paymentRequests.0.is_new_application', true));
        $this->getJson(route('finance.expense-requests.password', $item))
            ->assertOk()
            ->assertJsonPath('data.value', 'private-password')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(1, AuditLog::query()->where('action', 'expense_request_password_viewed')->count());
        $this->assertStringNotContainsString('private-password', AuditLog::query()->get()->toJson());
        $this->post(route('finance.expense-requests.payment', $item), [
            'paid_on' => '2026-09-11',
            'payment_reference' => 'PAY-INITIAL-001',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('paid', $item->fresh()->payment_status);
        $this->assertDatabaseHas('business_notifications', ['user_id' => $employee->id, 'type' => 'expense_request.paid']);
        $this->assertSame('2027-09-11', $item->fresh()->next_renewal_on->toDateString());
        $initialPayment = PersonalRequestPayment::query()->sole();
        $this->assertSame($item->id, $initialPayment->personal_request_id);
        $this->assertSame('initial_payment', $initialPayment->type);
        $this->assertSame('2026-09-11', $initialPayment->paid_on->toDateString());
        $this->assertSame('PAY-INITIAL-001', $initialPayment->reference);

        CarbonImmutable::setTestNow('2027-09-11 10:00:00');
        $this->artisan('finance:process-auto-renewals')
            ->expectsOutputToContain('自动续费处理完成：1 个项目。')
            ->assertSuccessful();
        $this->assertSame('2028-09-11', $item->fresh()->next_renewal_on->toDateString());
        $this->assertSame('2027-09-11', $item->fresh()->paid_on->toDateString());
        $this->assertDatabaseHas('personal_request_progress_logs', ['personal_request_id' => $item->id, 'action' => 'automatic_renewed']);
        $this->assertSame(2, PersonalRequestPayment::query()->where('personal_request_id', $item->id)->count());
        $this->get(route('finance.renewal-history', ['month' => '2027-09']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Finance/RenewalHistory')
                ->where('history.month', '2027-09')
                ->where('history.payments.total', 1)
                ->where('history.payments.data.0.type', 'automatic_renewal')
                ->where('history.payments.data.0.paid_on', '2027-09-11')
                ->where('history.totals.0.currency', 'CNY')
                ->where('history.totals.0.total', '1200'));
    }

    public function test_finance_renewals_are_sorted_by_payment_priority_and_nearest_relevant_date(): void
    {
        CarbonImmutable::setTestNow('2026-09-11 10:00:00');
        [$organization, $store] = $this->context();
        $finance = $this->user($organization, $store, 'organization-admin', '财务人员');
        $base = [
            'organization_id' => $organization->id,
            'kind' => 'expense_request',
            'submitter_id' => $finance->id,
            'description' => '排序测试',
            'category' => 'software',
            'amount' => '100.00',
            'currency' => 'CNY',
            'status' => 'approved',
            'renewal_mode' => 'manual',
            'billing_cycle' => 'monthly',
        ];
        PersonalRequest::query()->create([...$base, 'title' => '稍后付款', 'desired_date' => '2026-09-20', 'payment_status' => 'pending']);
        PersonalRequest::query()->create([...$base, 'title' => '优先付款', 'desired_date' => '2026-09-12', 'payment_status' => 'pending']);
        PersonalRequest::query()->create([...$base, 'title' => '稍后续费', 'desired_date' => '2026-08-01', 'payment_status' => 'paid', 'paid_on' => '2026-08-30', 'next_renewal_on' => '2026-09-30']);
        PersonalRequest::query()->create([...$base, 'title' => '优先续费', 'desired_date' => '2026-08-01', 'payment_status' => 'paid', 'paid_on' => '2026-08-12', 'next_renewal_on' => '2026-09-12']);

        $this->actingAs($finance)->withSession($this->contextSession($organization, $store))
            ->get(route('finance.renewals'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('paymentRequests.0.title', '优先付款')
                ->where('paymentRequests.1.title', '稍后付款')
                ->where('paymentRequests.2.title', '优先续费')
                ->where('paymentRequests.3.title', '稍后续费'));
    }

    /** @return array{Organization, Store} */
    private function context(): array
    {
        $organization = Organization::query()->create(['name' => 'Request Center', 'code' => 'request-center']);
        $store = $organization->stores()->create(['name' => 'Macfox Bike', 'shopify_domain' => 'requests.test', 'status' => 'active']);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        return [$organization, $store];
    }

    private function user(Organization $organization, Store $store, string $roleSlug, string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'email_verified_at' => now()]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);

        return $user;
    }

    private function contextSession(Organization $organization, Store $store): array
    {
        return ['current_organization_id' => $organization->id, 'current_store_id' => $store->id];
    }
}
