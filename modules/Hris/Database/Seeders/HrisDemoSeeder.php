<?php

declare(strict_types=1);

namespace Modules\Hris\Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;
use Modules\Core\Models\Branch;
use Modules\Hris\Enums\AttendanceSource;
use Modules\Hris\Enums\EmploymentStatus;
use Modules\Hris\Enums\LeaveType;
use Modules\Hris\Enums\PtkpStatus;
use Modules\Hris\Models\Department;
use Modules\Hris\Models\Employee;
use Modules\Hris\Models\LeaveRequest;
use Modules\Hris\Models\PayrollRun;
use Modules\Hris\Models\Position;
use Modules\Hris\Models\WorkShift;
use Modules\Hris\Services\AttendanceService;
use Modules\Hris\Services\LeaveRequestService;
use Modules\Hris\Services\PayrollCalculationService;
use Modules\Platform\Actions\ActivateCompanyModulesAction;
use Modules\Platform\Enums\CompanyModuleStatus;
use Modules\Platform\Models\SaasModule;

/**
 * Idempotently activates HRIS for Toko Maju and seeds employees, shifts, attendance, leave, and draft payroll.
 */
class HrisDemoSeeder extends Seeder
{
    /**
     * Run the database seeds in local/testing environments.
     */
    public function run(): void
    {
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->first();

        if (! $tokoMaju instanceof Company) {
            return;
        }

        /** Activate HRIS strictly for Toko Maju so Gym Sehat remains unsubscribed for RequiresModule tests. */
        $hrisModule = SaasModule::query()->where('slug', 'hris')->first();

        if ($hrisModule instanceof SaasModule) {
            app(ActivateCompanyModulesAction::class)->execute(
                company: $tokoMaju,
                moduleIds: [$hrisModule->getKey()],
                expiresAt: now()->addYear(),
                status: CompanyModuleStatus::Active,
            );
        }

        $companyId = $tokoMaju->getKey();
        $mainBranch = Branch::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('code', 'MAIN')
            ->first();

        $opsDepartment = Department::query()
            ->withoutGlobalScope('company')
            ->updateOrCreate(
                ['company_id' => $companyId, 'code' => 'OPS'],
                [
                    'name' => 'Operations',
                    'description' => 'Store operations and back-office administration',
                    'is_active' => true,
                ],
            );

        $salesDepartment = Department::query()
            ->withoutGlobalScope('company')
            ->updateOrCreate(
                ['company_id' => $companyId, 'code' => 'SLS'],
                [
                    'name' => 'Sales & Cashier',
                    'description' => 'Frontline retail sales and POS cashiers',
                    'is_active' => true,
                ],
            );

        $managerPosition = Position::query()
            ->withoutGlobalScope('company')
            ->updateOrCreate(
                ['company_id' => $companyId, 'code' => 'MGR'],
                [
                    'department_id' => $opsDepartment->getKey(),
                    'name' => 'Store Manager',
                    'base_salary_min' => '6500000.0000',
                    'base_salary_max' => '12000000.0000',
                ],
            );

        $cashierPosition = Position::query()
            ->withoutGlobalScope('company')
            ->updateOrCreate(
                ['company_id' => $companyId, 'code' => 'CSH'],
                [
                    'department_id' => $salesDepartment->getKey(),
                    'name' => 'Kasir & Staff',
                    'base_salary_min' => '3500000.0000',
                    'base_salary_max' => '6000000.0000',
                ],
            );

        $regularShift = WorkShift::query()
            ->withoutGlobalScope('company')
            ->updateOrCreate(
                ['company_id' => $companyId, 'name' => 'Shift Reguler Pagi'],
                [
                    'clock_in_time' => '08:00:00',
                    'clock_out_time' => '17:00:00',
                    'late_tolerance_minutes' => 15,
                    'is_overnight' => false,
                ],
            );

        /** Employee 1: PPh 21 and BPJS toggles enabled to demonstrate statutory deductions. */
        $taxableEmployee = Employee::query()
            ->withoutGlobalScope('company')
            ->updateOrCreate(
                ['company_id' => $companyId, 'employee_code' => 'EMP-001'],
                [
                    'branch_id' => $mainBranch?->getKey(),
                    'department_id' => $opsDepartment->getKey(),
                    'position_id' => $managerPosition->getKey(),
                    'work_shift_id' => $regularShift->getKey(),
                    'full_name' => 'Rina Wijaya',
                    'email' => 'rina@tokomaju.test',
                    'phone' => '081233330001',
                    'rfid_uid' => 'RFID-EMP-001',
                    'ptkp_status' => PtkpStatus::Tk0,
                    'base_salary' => '8000000.0000',
                    'fixed_allowance' => '1000000.0000',
                    'enable_pph21' => true,
                    'enable_bpjs_kesehatan' => true,
                    'enable_bpjs_ketenagakerjaan' => true,
                    'employment_status' => EmploymentStatus::Active,
                    'join_date' => now()->subYear()->toDateString(),
                    'bank_name' => 'BCA',
                    'bank_account_number' => '1234567001',
                    'bank_account_holder' => 'Rina Wijaya',
                ],
            );

        /** Employee 2: PPh 21 and BPJS toggles disabled to demonstrate zero-tax/zero-BPJS mode. */
        $nonTaxableEmployee = Employee::query()
            ->withoutGlobalScope('company')
            ->updateOrCreate(
                ['company_id' => $companyId, 'employee_code' => 'EMP-002'],
                [
                    'branch_id' => $mainBranch?->getKey(),
                    'department_id' => $salesDepartment->getKey(),
                    'position_id' => $cashierPosition->getKey(),
                    'work_shift_id' => $regularShift->getKey(),
                    'full_name' => 'Dedi Kurniawan',
                    'email' => 'dedi@tokomaju.test',
                    'phone' => '081233330002',
                    'rfid_uid' => 'RFID-EMP-002',
                    'ptkp_status' => PtkpStatus::K1,
                    'base_salary' => '4500000.0000',
                    'fixed_allowance' => '500000.0000',
                    'enable_pph21' => false,
                    'enable_bpjs_kesehatan' => false,
                    'enable_bpjs_ketenagakerjaan' => false,
                    'employment_status' => EmploymentStatus::Active,
                    'join_date' => now()->subMonths(6)->toDateString(),
                    'bank_name' => 'Mandiri',
                    'bank_account_number' => '8880001002',
                    'bank_account_holder' => 'Dedi Kurniawan',
                ],
            );

        $attendanceService = app(AttendanceService::class);
        $dayOne = now()->startOfMonth();
        $dayTwo = $dayOne->copy()->addDay();

        /** Seed current-month attendance logs idempotently (day 1 on-time + overtime, day 2 late). */
        $attendanceService->recordClockIn(
            employee: $taxableEmployee,
            clockInAt: $dayOne->copy()->setTime(7, 55),
            source: AttendanceSource::Manual,
            branch: $mainBranch,
            shift: $regularShift,
        );
        $attendanceService->recordClockOut(
            employee: $taxableEmployee,
            clockOutAt: $dayOne->copy()->setTime(18, 0),
            source: AttendanceSource::Manual,
        );

        $attendanceService->recordClockIn(
            employee: $nonTaxableEmployee,
            clockInAt: $dayTwo->copy()->setTime(8, 25),
            source: AttendanceSource::Web,
            branch: $mainBranch,
            shift: $regularShift,
        );
        $attendanceService->recordClockOut(
            employee: $nonTaxableEmployee,
            clockOutAt: $dayTwo->copy()->setTime(17, 0),
            source: AttendanceSource::Web,
        );

        /** Seed one pending leave request idempotently. */
        $leaveStart = $dayOne->copy()->addDays(10)->toDateString();
        $leaveExists = LeaveRequest::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('employee_id', $nonTaxableEmployee->getKey())
            ->whereDate('start_date', $leaveStart)
            ->exists();

        if (! $leaveExists) {
            app(LeaveRequestService::class)->submitLeave(
                employee: $nonTaxableEmployee,
                type: LeaveType::Annual,
                startDate: $leaveStart,
                endDate: $dayOne->copy()->addDays(11)->toDateString(),
                reason: 'Keperluan keluarga terencana',
            );
        }

        /** Generate one draft payroll run once for the current month showing both switch states. */
        $periodMonth = (int) now()->month;
        $periodYear = (int) now()->year;
        $seedPayrollNumber = sprintf('PAY-SEED-%04d%02d-001', $periodYear, $periodMonth);

        $payrollExists = PayrollRun::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('payroll_number', $seedPayrollNumber)
            ->exists();

        if (! $payrollExists) {
            app(PayrollCalculationService::class)->generatePayrollRun(
                company: $tokoMaju,
                periodMonth: $periodMonth,
                periodYear: $periodYear,
                branch: $mainBranch,
                applyPph21: true,
                applyBpjs: true,
                payrollNumber: $seedPayrollNumber,
                notes: 'Seeded draft payroll demonstrating optional PPh 21 & BPJS switches',
            );
        }
    }
}
