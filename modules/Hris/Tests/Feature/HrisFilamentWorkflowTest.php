<?php

declare(strict_types=1);

namespace Modules\Hris\Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Accounting\Enums\JournalStatus;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Journal;
use Modules\Core\Models\Branch;
use Modules\Hris\Enums\AttendanceSource;
use Modules\Hris\Enums\AttendanceStatus;
use Modules\Hris\Enums\LeaveStatus;
use Modules\Hris\Enums\LeaveType;
use Modules\Hris\Enums\PayrollRunStatus;
use Modules\Hris\Filament\Resources\AttendanceResource\Pages\ListAttendances;
use Modules\Hris\Filament\Resources\LeaveRequestResource\Pages\ListLeaveRequests;
use Modules\Hris\Filament\Resources\PayrollRunResource\Pages\ListPayrollRuns;
use Modules\Hris\Filament\Resources\PayrollRunResource\Pages\ViewPayrollRun;
use Modules\Hris\Filament\Resources\PayrollRunResource\RelationManagers\PayslipsRelationManager;
use Modules\Hris\Models\Attendance;
use Modules\Hris\Models\Employee;
use Modules\Hris\Models\LeaveRequest;
use Modules\Hris\Models\PayrollRun;
use Modules\Hris\Models\Payslip;
use Modules\Platform\Enums\PaymentGatewayMode;
use Modules\Platform\Filament\Tenant\Pages\CompanySettingsPage;
use Modules\Platform\Models\CompanySetting;
use Tests\TestCase;

/**
 * Feature tests verifying Filament HRIS workflows: settings toggles, attendance, leave approval, and payroll to accounting.
 */
class HrisFilamentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure CompanySettingsPage persists HRIS default tax/BPJS toggles strictly for Toko Maju.
     */
    public function test_company_settings_page_persists_hris_default_payroll_toggles(): void
    {
        /** Arrange seeded Toko Maju and Gym Sehat. */
        $this->seed(DatabaseSeeder::class);

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $gymSehat = Company::query()->where('slug', 'gym-sehat')->firstOrFail();

        $this->actingAs($owner1);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tokoMaju, isQuiet: true);

        /** Act by enabling default PPh 21 and BPJS toggles on Toko Maju CompanySettingsPage. */
        Livewire::test(CompanySettingsPage::class)
            ->fillForm([
                'currency' => 'IDR',
                'tax_rate' => '0.00',
                'timezone' => 'Asia/Jakarta',
                'invoice_prefix' => 'INV-',
                'payment_gateway_mode' => PaymentGatewayMode::PlatformMidtrans->value,
                'enable_pph21' => true,
                'enable_bpjs_kesehatan' => true,
                'enable_bpjs_ketenagakerjaan' => true,
                'bpjs_jkk_rate' => '0.0054',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $tokoSettings = CompanySetting::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();
        $gymSettings = CompanySetting::query()->withoutGlobalScope('company')->where('company_id', $gymSehat->getKey())->firstOrFail();

        /** Assert Toko Maju updated while Gym Sehat remained untouched. */
        $this->assertTrue($tokoSettings->enable_pph21);
        $this->assertTrue($tokoSettings->enable_bpjs_kesehatan);
        $this->assertTrue($tokoSettings->enable_bpjs_ketenagakerjaan);
        $this->assertSame('0.0054', $tokoSettings->bpjs_jkk_rate);

        $this->assertFalse($gymSettings->enable_pph21);
        $this->assertSame('0.0024', $gymSettings->bpjs_jkk_rate);
    }

    /**
     * Ensure AttendanceResource records clock-in and clock-out via Livewire Filament actions.
     */
    public function test_attendance_resource_executes_clock_in_and_clock_out_actions(): void
    {
        /** Arrange seeded Toko Maju and EMP-001. */
        $this->seed(DatabaseSeeder::class);

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $employee = Employee::query()->withoutGlobalScope('company')
            ->where('company_id', $tokoMaju->getKey())
            ->where('employee_code', 'EMP-001')
            ->firstOrFail();

        $this->actingAs($owner1);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tokoMaju, isQuiet: true);

        $targetDate = now()->startOfMonth()->addDays(4);

        /** Act 1: Record Clock-In 30 minutes past 08:00 shift start. */
        Livewire::test(ListAttendances::class)
            ->callAction('recordClockIn', data: [
                'employee_id' => $employee->getKey(),
                'clock_in_at' => $targetDate->copy()->setTime(8, 30)->format('Y-m-d H:i:s'),
                'source' => AttendanceSource::Manual->value,
                'notes' => 'Morning manual entry',
            ])
            ->assertHasNoActionErrors();

        $attendance = Attendance::query()
            ->where('employee_id', $employee->getKey())
            ->whereDate('attendance_date', $targetDate->toDateString())
            ->firstOrFail();

        $this->assertSame(AttendanceStatus::Late, $attendance->status);
        $this->assertSame(30, $attendance->late_minutes);
        $this->assertNull($attendance->clock_out);

        /** Act 2: Record Clock-Out 60 minutes past 17:00 shift end. */
        Livewire::test(ListAttendances::class)
            ->callAction(TestAction::make('recordClockOut')->table($attendance), data: [
                'clock_out_at' => $targetDate->copy()->setTime(18, 0)->format('Y-m-d H:i:s'),
                'source' => AttendanceSource::Manual->value,
            ])
            ->assertHasNoActionErrors();

        $attendance->refresh();
        $this->assertNotNull($attendance->clock_out);
        $this->assertSame(60, $attendance->overtime_minutes);
    }

    /**
     * Ensure approving a pending leave request via LeaveRequestResource populates attendance rows across dates.
     */
    public function test_leave_request_approve_and_reject_actions_update_attendance_correctly(): void
    {
        /** Arrange seeded Toko Maju (1 pending annual leave for EMP-002 covering 2 days). */
        $this->seed(DatabaseSeeder::class);

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $pendingLeave = LeaveRequest::query()->withoutGlobalScope('company')
            ->where('company_id', $tokoMaju->getKey())
            ->where('status', LeaveStatus::Pending->value)
            ->firstOrFail();

        $this->actingAs($owner1);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tokoMaju, isQuiet: true);

        /** Act 1: Approve the seeded 2-day leave request via Table Action. */
        Livewire::test(ListLeaveRequests::class)
            ->callAction(TestAction::make('approve')->table($pendingLeave))
            ->assertHasNoActionErrors();

        $this->assertSame(LeaveStatus::Approved, $pendingLeave->refresh()->status);

        $leaveAttendances = Attendance::query()
            ->where('employee_id', $pendingLeave->employee_id)
            ->whereDate('attendance_date', '>=', $pendingLeave->start_date->toDateString())
            ->whereDate('attendance_date', '<=', $pendingLeave->end_date->toDateString())
            ->get();

        $this->assertCount(2, $leaveAttendances);
        $this->assertTrue($leaveAttendances->every(static fn (Attendance $row): bool => $row->status === AttendanceStatus::Leave));

        /** Act 2: Submit a second leave request and reject it via Table Action. */
        $rejectDate = now()->startOfMonth()->addDays(15)->toDateString();

        Livewire::test(ListLeaveRequests::class)
            ->callAction('submitLeave', data: [
                'employee_id' => $pendingLeave->employee_id,
                'type' => LeaveType::Permission->value,
                'start_date' => $rejectDate,
                'end_date' => $rejectDate,
                'reason' => 'Personal errand',
            ])
            ->assertHasNoActionErrors();

        $secondLeave = LeaveRequest::query()
            ->where('employee_id', $pendingLeave->employee_id)
            ->where('status', LeaveStatus::Pending->value)
            ->latest('id')
            ->firstOrFail();

        Livewire::test(ListLeaveRequests::class)
            ->callAction(TestAction::make('reject')->table($secondLeave), data: [
                'rejection_reason' => 'Insufficient shift coverage',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(LeaveStatus::Rejected, $secondLeave->refresh()->status);
        $this->assertSame(
            0,
            Attendance::query()
                ->where('employee_id', $pendingLeave->employee_id)
                ->whereDate('attendance_date', $rejectDate)
                ->count(),
        );
    }

    /**
     * Ensure end-to-end Filament payroll workflow generates, inspects payslip modal, approves, pays, and posts balanced journal.
     */
    public function test_end_to_end_filament_payroll_generate_approve_pay_and_accounting_journal(): void
    {
        /** Arrange seeded Toko Maju with active HRIS and Accounting modules. */
        $this->seed(DatabaseSeeder::class);

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $branch = Branch::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();
        $bankAccount = Account::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->where('code', '1102')->firstOrFail();

        $this->actingAs($owner1);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tokoMaju, isQuiet: true);

        /** Step 1: Generate a fresh payroll run for next month via ListPayrollRuns Header Action. */
        $targetDate = now()->addMonth();

        Livewire::test(ListPayrollRuns::class)
            ->callAction('generatePayroll', data: [
                'period_month' => (int) $targetDate->month,
                'period_year' => (int) $targetDate->year,
                'branch_id' => $branch->getKey(),
                'apply_pph21' => true,
                'apply_bpjs' => true,
                'notes' => 'Filament UI generated payroll',
            ])
            ->assertHasNoActionErrors();

        $generatedRun = PayrollRun::query()
            ->where('period_month', (int) $targetDate->month)
            ->where('period_year', (int) $targetDate->year)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(PayrollRunStatus::Draft, $generatedRun->status);
        $this->assertCount(2, $generatedRun->payslips);

        /** Step 2: Inspect PayslipsRelationManager and open Lihat Slip Gaji modal for EMP-001. */
        $taxablePayslip = Payslip::query()
            ->where('payroll_run_id', $generatedRun->getKey())
            ->whereHas('employee', static fn ($query) => $query->where('employee_code', 'EMP-001'))
            ->firstOrFail();

        $payslipComponent = Livewire::test(PayslipsRelationManager::class, [
            'ownerRecord' => $generatedRun,
            'pageClass' => ViewPayrollRun::class,
        ])
            ->assertCanSeeTableRecords([$taxablePayslip])
            ->mountAction(TestAction::make('viewPayslip')->table($taxablePayslip))
            ->assertActionMounted(TestAction::make('viewPayslip')->table($taxablePayslip));

        $modalHtml = $payslipComponent->instance()->getMountedAction()->getModalContent()->render();
        $this->assertStringContainsString('Pendapatan (Earnings)', $modalHtml);
        $this->assertStringContainsString('Potongan Karyawan (Deductions)', $modalHtml);
        $this->assertStringContainsString('Take Home Pay', $modalHtml);
        $this->assertStringContainsString('Rina Wijaya', $modalHtml);

        /** Step 3: Approve Payroll on ViewPayrollRun. */
        Livewire::test(ViewPayrollRun::class, ['record' => $generatedRun->getKey()])
            ->callAction('approvePayroll')
            ->assertHasNoActionErrors();

        $this->assertSame(PayrollRunStatus::Approved, $generatedRun->refresh()->status);

        /** Step 4: Pay Payroll selecting 1102 Bank account on ViewPayrollRun. */
        Livewire::test(ViewPayrollRun::class, ['record' => $generatedRun->getKey()])
            ->callAction('payPayroll', data: [
                'payment_account_id' => $bankAccount->getKey(),
            ])
            ->assertHasNoActionErrors();

        $generatedRun->refresh();
        $this->assertSame(PayrollRunStatus::Paid, $generatedRun->status);
        $this->assertSame($bankAccount->getKey(), $generatedRun->payment_account_id);

        /** Step 5: Verify Accounting journal (Debit 6101 = Credit 1102 + Credit 2102 + Credit 2103) created and balanced. */
        $journal = Journal::query()
            ->with('items')
            ->where('reference_type', PayrollRun::class)
            ->where('reference_id', $generatedRun->getKey())
            ->firstOrFail();

        $this->assertSame(JournalStatus::Posted, $journal->status);

        $accounts = Account::query()->get()->keyBy('code');
        $itemsByAccount = $journal->items->keyBy('account_id');

        $expectedExpenseDebit = bcadd(
            bcadd((string) $generatedRun->total_net, (string) $generatedRun->total_pph21, 4),
            bcadd((string) $generatedRun->total_bpjs_employee, (string) $generatedRun->total_bpjs_company, 4),
            4,
        );
        $expectedBpjsCredit = bcadd((string) $generatedRun->total_bpjs_employee, (string) $generatedRun->total_bpjs_company, 4);

        $this->assertSame($expectedExpenseDebit, $itemsByAccount->get($accounts->get('6101')->getKey())->debit);
        $this->assertSame((string) $generatedRun->total_net, $itemsByAccount->get($bankAccount->getKey())->credit);
        $this->assertSame((string) $generatedRun->total_pph21, $itemsByAccount->get($accounts->get('2102')->getKey())->credit);
        $this->assertSame($expectedBpjsCredit, $itemsByAccount->get($accounts->get('2103')->getKey())->credit);
    }
}
