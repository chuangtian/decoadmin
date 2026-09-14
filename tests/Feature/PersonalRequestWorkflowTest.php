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

        $this->get(route('technical-requests.index', ['tab' => 'overview']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Requests/Technical')
            ->where('scope', 'mine')
            ->where('canViewOverview', false)
            ->where('filters.tab', 'list')
            ->where('requests.total', 1));
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
        $this->assertNotNull($item->fresh()->accepted_at);
        $this->put(route('technical-requests.progress', $item), ['action' => 'progress', 'note' => '接口已完成'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->put(route('technical-requests.progress', $item), ['action' => 'complete', 'note' => '已上线测试'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('completed', $item->fresh()->status);
        $this->assertDatabaseCount('personal_request_progress_logs', 4);
        $this->assertDatabaseHas('audit_logs', ['subject_id' => $item->id, 'action' => 'technical_request_completed']);
        $this->assertSame(4, BusinessNotification::query()->where('user_id', $employee->id)->count());
    }

    public function test_technical_kpi_is_scoped_and_attributed_to_the_developer_who_accepted_the_work(): void
    {
        CarbonImmutable::setTestNow('2026-09-11 12:00:00');
        [$organization, $store] = $this->context();
        $employee = $this->user($organization, $store, 'operator', '需求提交人');
        $developer = $this->user($organization, $store, 'developer', '开发甲');

        PersonalRequest::query()->create([
            'organization_id' => $organization->id,
            'kind' => 'technical',
            'submitter_id' => $employee->id,
            'assignee_id' => $developer->id,
            'title' => '按时完成需求',
            'description' => '用于验证处理周期与按时率。',
            'category' => 'feature',
            'priority' => 'medium',
            'desired_date' => '2026-09-04',
            'status' => 'completed',
            'accepted_at' => '2026-09-01 08:00:00',
            'completed_at' => '2026-09-03 08:00:00',
        ]);
        PersonalRequest::query()->create([
            'organization_id' => $organization->id,
            'kind' => 'technical',
            'submitter_id' => $employee->id,
            'assignee_id' => $developer->id,
            'title' => '逾期处理中需求',
            'description' => '用于验证开发人员逾期工作量。',
            'category' => 'bug',
            'priority' => 'high',
            'desired_date' => '2026-09-05',
            'status' => 'assigned',
            'accepted_at' => '2026-09-04 08:00:00',
        ]);
        PersonalRequest::query()->create([
            'organization_id' => $organization->id,
            'kind' => 'technical',
            'submitter_id' => $employee->id,
            'title' => '待接受需求',
            'description' => '已审批但尚未被开发人员接受。',
            'category' => 'automation',
            'priority' => 'low',
            'desired_date' => '2026-09-20',
            'status' => 'approved',
        ]);
        PersonalRequest::query()->create([
            'organization_id' => $organization->id,
            'kind' => 'technical',
            'submitter_id' => $employee->id,
            'title' => '待审批需求',
            'description' => '尚未进入开发人员工作量。',
            'category' => 'data',
            'priority' => 'low',
            'desired_date' => '2026-09-21',
            'status' => 'pending_approval',
        ]);

        $this->actingAs($employee)->withSession($this->contextSession($organization, $store))
            ->get(route('technical-requests.index', ['tab' => 'overview']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canViewOverview', false)
                ->where('filters.tab', 'list'));

        $this->actingAs($developer)->withSession($this->contextSession($organization, $store))
            ->get(route('technical-requests.index', ['tab' => 'overview']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scope', 'all')
                ->where('canViewOverview', true)
                ->where('filters.tab', 'overview')
                ->where('summary.total', 4)
                ->where('summary.pending_approval', 1)
                ->where('summary.unassigned', 1)
                ->where('summary.in_progress', 1)
                ->where('summary.completed', 1)
                ->where('summary.overdue', 1)
                ->where('summary.on_time_rate', 100)
                ->where('summary.average_turnaround_days', 2)
                ->where('summary.status_counts.assigned', 1)
                ->has('summary.developer_performance', 1)
                ->where('summary.developer_performance.0.developer', '开发甲')
                ->where('summary.developer_performance.0.total', 2)
                ->where('summary.developer_performance.0.in_progress', 1)
                ->where('summary.developer_performance.0.completed', 1)
                ->where('summary.developer_performance.0.overdue', 1)
                ->where('summary.developer_performance.0.on_time_rate', 100)
                ->where('summary.developer_performance.0.average_turnaround_days', 2));
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

    public function test_approved_expense_claim_moves_through_finance_reimbursement_and_preserves_invoice_access(): void
    {
        CarbonImmutable::setTestNow('2026-09-12 10:00:00');
        Storage::fake('local');
        [$organization, $store] = $this->context();
        $employee = $this->user($organization, $store, 'marketing', '报销员工');
        $approver = $this->user($organization, $store, 'super-admin', '超级管理员');
        $finance = $this->user($organization, $store, 'organization-admin', '财务人员');

        $this->actingAs($employee)->withSession($this->contextSession($organization, $store))
            ->post(route('expense-claims.store'), [
                'title' => '客户拜访交通费',
                'category' => 'travel',
                'amount' => '128.50',
                'currency' => 'CNY',
                'expense_date' => '2026-09-10',
                'description' => '客户拜访往返交通。',
                'images' => [UploadedFile::fake()->image('invoice.png')],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $item = PersonalRequest::query()->sole();
        $attachment = PersonalRequestAttachment::query()->sole();
        $this->actingAs($approver)->withSession($this->contextSession($organization, $store))
            ->put(route('request-approvals.review', $item), ['action' => 'approve', 'note' => '票据与用途一致'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame('approved', $item->status);
        $this->assertSame('pending', $item->payment_status);
        $this->assertDatabaseHas('business_notifications', [
            'user_id' => $finance->id,
            'type' => 'expense.reimbursement_required',
        ]);

        $this->actingAs($employee)->withSession($this->contextSession($organization, $store))
            ->get(route('finance.reimbursements'))->assertForbidden();

        $this->actingAs($finance)->withSession($this->contextSession($organization, $store))
            ->get(route('finance.reimbursements'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Finance/Reimbursements')
                ->where('today', '2026-09-12')
                ->where('filters.payment_status', 'pending')
                ->where('summary.pending', 1)
                ->where('summary.paid', 0)
                ->where('reimbursements.total', 1)
                ->where('reimbursements.data.0.reference_no', $item->reference_no)
                ->where('reimbursements.data.0.payment_status', 'pending')
                ->has('reimbursements.data.0.attachments', 1));
        $this->get(route('personal-requests.attachments.show', [$item, $attachment]))
            ->assertOk();

        $this->post(route('finance.reimbursements.payment', $item), [
            'paid_on' => '2026-09-09',
            'payment_reference' => 'BANK-INVALID',
        ])->assertSessionHasErrors('paid_on');
        $this->post(route('finance.reimbursements.payment', $item), [
            'paid_on' => '2026-09-12',
            'payment_reference' => 'BANK-20260912-001',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame('paid', $item->payment_status);
        $this->assertSame($finance->id, $item->paid_by);
        $this->assertSame('2026-09-12', $item->paid_on->toDateString());
        $this->assertSame('BANK-20260912-001', $item->payment_reference);
        $this->assertDatabaseHas('personal_request_progress_logs', [
            'personal_request_id' => $item->id,
            'user_id' => $finance->id,
            'action' => 'reimbursed',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'subject_id' => $item->id,
            'action' => 'expense_claim_reimbursed',
        ]);
        $this->assertDatabaseHas('business_notifications', [
            'user_id' => $employee->id,
            'type' => 'expense.reimbursed',
        ]);
        $this->post(route('finance.reimbursements.payment', $item), [
            'paid_on' => '2026-09-12',
        ])->assertStatus(409);

        $this->get(route('finance.reimbursements', ['payment_status' => 'paid']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.payment_status', 'paid')
                ->where('summary.pending', 0)
                ->where('summary.paid', 1)
                ->where('reimbursements.data.0.payment_reference', 'BANK-20260912-001'));

        $this->actingAs($employee)->withSession($this->contextSession($organization, $store))
            ->get(route('expense-claims.index', ['status' => 'reimbursed']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.status', 'reimbursed')
                ->where('requests.total', 1)
                ->where('requests.data.0.payment_status', 'paid'));
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
                'approval_required' => true,
                'software_url' => 'https://software.example.com', 'software_account' => 'team@example.com',
                'software_password' => 'private-password', 'software_payment_method' => '公司信用卡尾号 1234', 'renewal_mode' => 'automatic',
                'billing_cycle' => 'annual',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $item = PersonalRequest::query()->sole();
        $this->assertSame('expense_request', $item->kind);
        $this->assertStringStartsWith('CR-', $item->reference_no);
        $this->assertSame('team@example.com', $item->software_account);
        $this->assertSame('private-password', $item->software_password);
        $this->assertSame('公司信用卡尾号 1234', $item->software_payment_method);
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
            ->where('requests.data.0.approval_required', true)
            ->where('requests.data.0.software_account', 'team@example.com')
            ->where('requests.data.0.software_password_set', true)
            ->where('requests.data.0.software_payment_method', '公司信用卡尾号 1234')
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
            ->where('paymentRequests.0.software_payment_method', '公司信用卡尾号 1234')
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

    public function test_cost_application_can_skip_approval_and_leave_software_credentials_empty(): void
    {
        CarbonImmutable::setTestNow('2026-09-12 10:00:00');
        [$organization, $store] = $this->context();
        $employee = $this->user($organization, $store, 'operator', '免审批申请人');
        $finance = $this->user($organization, $store, 'organization-admin', '财务人员');
        $superAdmin = $this->user($organization, $store, 'super-admin', '超级管理员');

        $this->actingAs($employee)->withSession($this->contextSession($organization, $store))
            ->post(route('expense-requests.store'), [
                'title' => '采购无需账号的软件',
                'category' => 'software',
                'amount' => '88.00',
                'currency' => 'CNY',
                'desired_date' => '2026-09-20',
                'description' => '线下付款后由供应商直接开通。',
                'approval_required' => false,
                'software_payment_method' => '对公转账',
                'renewal_mode' => 'manual',
                'billing_cycle' => 'annual',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $item = PersonalRequest::query()->sole();
        $this->assertFalse($item->approval_required);
        $this->assertSame('approved', $item->status);
        $this->assertSame('pending', $item->payment_status);
        $this->assertNull($item->reviewer_id);
        $this->assertNull($item->software_url);
        $this->assertNull($item->software_account);
        $this->assertNull($item->software_password);
        $this->assertSame('对公转账', $item->software_payment_method);
        $this->assertNotNull($item->reviewed_at);
        $this->assertDatabaseHas('personal_request_progress_logs', [
            'personal_request_id' => $item->id,
            'user_id' => $employee->id,
            'action' => 'approval_exempted',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'subject_id' => $item->id,
            'action' => 'expense_request_created_without_approval',
        ]);
        $this->assertDatabaseMissing('business_notifications', [
            'user_id' => $superAdmin->id,
            'type' => 'expense_request.submitted',
        ]);
        $this->assertDatabaseHas('business_notifications', [
            'user_id' => $finance->id,
            'type' => 'expense_request.payment_required',
        ]);

        $this->get(route('expense-requests.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('requests.data.0.approval_required', false)
            ->where('requests.data.0.software_url', null)
            ->where('requests.data.0.software_account', null)
            ->where('requests.data.0.software_payment_method', '对公转账')
            ->where('requests.data.0.software_password_set', false));

        $this->actingAs($superAdmin)->withSession($this->contextSession($organization, $store))
            ->get(route('request-approvals.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('requests.total', 0));
        $this->put(route('request-approvals.review', $item), ['action' => 'approve'])
            ->assertStatus(409);
    }

    public function test_super_admin_can_create_a_cost_application_for_an_active_organization_member(): void
    {
        CarbonImmutable::setTestNow('2026-09-14 10:00:00');
        [$organization, $store] = $this->context();
        $employee = $this->user($organization, $store, 'operator', '历史申请员工');
        $superAdmin = $this->user($organization, $store, 'super-admin', '代填管理员');
        $outsider = User::factory()->create(['name' => '其他组织成员', 'status' => 'active']);

        $this->actingAs($employee)->withSession($this->contextSession($organization, $store))
            ->get(route('expense-requests.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.canChooseApplicant', false)
                ->where('options.currentApplicantId', $employee->id)
                ->has('options.applicants', 0));

        $this->actingAs($superAdmin)->withSession($this->contextSession($organization, $store))
            ->get(route('expense-requests.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.canChooseApplicant', true)
                ->where('options.currentApplicantId', $superAdmin->id)
                ->has('options.applicants', 2)
                ->where('options.applicants.0.name', '代填管理员')
                ->where('options.applicants.1.name', '历史申请员工'));

        $payload = [
            'applicant_id' => $employee->id,
            'title' => '补录历史费用申请',
            'category' => 'other',
            'amount' => '320.00',
            'currency' => 'CNY',
            'desired_date' => '2026-09-20',
            'description' => '管理员根据已有申请资料代为补录。',
            'approval_required' => true,
        ];

        $this->post(route('expense-requests.store'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $item = PersonalRequest::query()->sole();
        $this->assertSame($employee->id, $item->submitter_id);
        $audit = AuditLog::query()->where('subject_id', $item->id)->where('action', 'expense_request_created')->sole();
        $this->assertSame($superAdmin->id, $audit->user_id);
        $this->assertSame($employee->id, $audit->metadata['submitter_id']);
        $this->assertTrue($audit->metadata['submitted_on_behalf']);

        $this->actingAs($employee)->withSession($this->contextSession($organization, $store))
            ->get(route('expense-requests.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scope', 'mine')
                ->where('requests.total', 1)
                ->where('requests.data.0.submitter', '历史申请员工'));

        $this->actingAs($employee)->withSession($this->contextSession($organization, $store))
            ->post(route('expense-requests.store'), [...$payload, 'applicant_id' => $superAdmin->id])
            ->assertSessionHasErrors('applicant_id');
        $this->assertSame(1, PersonalRequest::query()->count());

        $this->actingAs($superAdmin)->withSession($this->contextSession($organization, $store))
            ->post(route('expense-requests.store'), [...$payload, 'applicant_id' => $outsider->id])
            ->assertSessionHasErrors('applicant_id');
        $this->assertSame(1, PersonalRequest::query()->count());
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

    public function test_automatic_subscription_cancellation_records_due_cycle_and_excludes_future_finance_totals(): void
    {
        CarbonImmutable::setTestNow('2026-09-20 10:00:00');
        [$organization, $store] = $this->context();
        $employee = $this->user($organization, $store, 'operator', '订阅申请人');
        $finance = $this->user($organization, $store, 'organization-admin', '财务人员');

        $item = PersonalRequest::query()->create([
            'organization_id' => $organization->id,
            'kind' => 'expense_request',
            'submitter_id' => $employee->id,
            'title' => '自动续费软件',
            'description' => '取消日期规则测试',
            'category' => 'software',
            'amount' => '99.00',
            'currency' => 'CNY',
            'desired_date' => '2026-08-10',
            'status' => 'approved',
            'approval_required' => false,
            'payment_status' => 'paid',
            'paid_on' => '2026-08-10',
            'next_renewal_on' => '2026-09-10',
            'renewal_mode' => 'automatic',
            'billing_cycle' => 'monthly',
        ]);
        PersonalRequestPayment::query()->create([
            'organization_id' => $organization->id,
            'personal_request_id' => $item->id,
            'paid_by' => $finance->id,
            'type' => 'initial_payment',
            'amount' => '99.00',
            'currency' => 'CNY',
            'paid_on' => '2026-08-10',
        ]);

        $this->actingAs($employee)->withSession($this->contextSession($organization, $store))
            ->get(route('expense-requests.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('today', '2026-09-20')
            ->where('requests.data.0.renewal_status', 'active')
            ->where('requests.data.0.can_cancel_renewal', true));
        $this->put(route('expense-requests.cancel-renewal', $item), ['cancelled_on' => '2026-09-10'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame('cancelled', $item->renewal_status);
        $this->assertSame('2026-09-10', $item->cancelled_on->toDateString());
        $this->assertSame($employee->id, $item->cancelled_by);
        $this->assertSame('2026-09-10', $item->paid_on->toDateString());
        $this->assertNull($item->next_renewal_on);
        $automaticPayment = PersonalRequestPayment::query()
            ->where('personal_request_id', $item->id)
            ->where('type', 'automatic_renewal')
            ->sole();
        $this->assertSame('2026-09-10', $automaticPayment->paid_on->toDateString());
        $this->assertDatabaseHas('personal_request_progress_logs', [
            'personal_request_id' => $item->id,
            'action' => 'renewal_cancelled',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'subject_id' => $item->id,
            'action' => 'expense_request_renewal_cancelled',
        ]);
        $this->assertDatabaseHas('business_notifications', [
            'user_id' => $finance->id,
            'type' => 'expense_request.renewal_cancelled',
        ]);

        $this->put(route('expense-requests.cancel-renewal', $item), ['cancelled_on' => '2026-09-10'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $item->progressLogs()->where('action', 'renewal_cancelled')->count());
        $this->assertSame(1, AuditLog::query()
            ->where('subject_id', $item->id)
            ->where('action', 'expense_request_renewal_cancelled')
            ->count());

        $this->actingAs($finance)->withSession($this->contextSession($organization, $store))
            ->get(route('finance.renewals'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('paymentRequests', []));
        $this->get(route('finance.renewal-history', ['month' => '2026-09']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('history.payments.total', 1)
            ->where('history.payments.data.0.type', 'automatic_renewal')
            ->where('history.totals.0.total', '99'));

        CarbonImmutable::setTestNow('2026-10-20 10:00:00');
        $this->artisan('finance:process-auto-renewals')
            ->expectsOutputToContain('自动续费处理完成：0 个项目。')
            ->assertSuccessful();
        $this->assertSame(2, PersonalRequestPayment::query()->where('personal_request_id', $item->id)->count());
    }

    public function test_manual_subscription_cancellation_preserves_paid_history_and_enforces_owner_and_dates(): void
    {
        CarbonImmutable::setTestNow('2026-09-20 10:00:00');
        [$organization, $store] = $this->context();
        $employee = $this->user($organization, $store, 'operator', '订阅申请人');
        $otherEmployee = $this->user($organization, $store, 'marketing', '其他员工');

        $item = PersonalRequest::query()->create([
            'organization_id' => $organization->id,
            'kind' => 'expense_request',
            'submitter_id' => $employee->id,
            'title' => '手动续费软件',
            'description' => '手动取消测试',
            'category' => 'software',
            'amount' => '50.00',
            'currency' => 'USD',
            'desired_date' => '2026-09-01',
            'status' => 'approved',
            'payment_status' => 'paid',
            'paid_on' => '2026-09-01',
            'next_renewal_on' => '2026-10-01',
            'renewal_mode' => 'manual',
            'billing_cycle' => 'monthly',
        ]);
        PersonalRequestPayment::query()->create([
            'organization_id' => $organization->id,
            'personal_request_id' => $item->id,
            'paid_by' => null,
            'type' => 'initial_payment',
            'amount' => '50.00',
            'currency' => 'USD',
            'paid_on' => '2026-09-01',
        ]);

        $this->actingAs($otherEmployee)->withSession($this->contextSession($organization, $store))
            ->put(route('expense-requests.cancel-renewal', $item), ['cancelled_on' => '2026-09-20'])
            ->assertForbidden();
        $this->actingAs($employee)->withSession($this->contextSession($organization, $store))
            ->put(route('expense-requests.cancel-renewal', $item), ['cancelled_on' => '2026-08-31'])
            ->assertSessionHasErrors('cancelled_on');
        $this->put(route('expense-requests.cancel-renewal', $item), ['cancelled_on' => '2026-09-20'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame('cancelled', $item->renewal_status);
        $this->assertNull($item->next_renewal_on);
        $this->assertSame(1, PersonalRequestPayment::query()->where('personal_request_id', $item->id)->count());
        $this->get(route('expense-requests.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('requests.data.0.renewal_status', 'cancelled')
            ->where('requests.data.0.cancelled_on', '2026-09-20')
            ->where('requests.data.0.can_cancel_renewal', false));
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
