<?php

declare(strict_types=1);

namespace Modules\Kpi\Tests\Unit;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Modules\Core\Models\Branch;
use Modules\Hris\Enums\AttendanceSource;
use Modules\Hris\Enums\AttendanceStatus;
use Modules\Hris\Enums\EmploymentStatus;
use Modules\Hris\Enums\PtkpStatus;
use Modules\Hris\Models\Attendance;
use Modules\Hris\Models\Employee;
use Modules\Kpi\Enums\KpiMetricType;
use Modules\Kpi\Enums\KpiPeriodStatus;
use Modules\Kpi\Enums\KpiPeriodType;
use Modules\Kpi\Models\KpiPeriod;
use Modules\Kpi\Services\KpiMetricSyncService;
use Modules\Transaction\Enums\OrderChannel;
use Modules\Transaction\Enums\OrderStatus;
use Modules\Transaction\Enums\PaymentStatus;
use Modules\Transaction\Models\Order;
use Tests\TestCase;

/**
 * Unit tests verifying KpiMetricSyncService accuracy across Transaction orders and HRIS attendances.
 */
class KpiMetricSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure sales_revenue sums only paid non-cancelled orders for the target employee inside the period window.
     */
    public function test_resolves_sales_revenue_from_paid_orders_within_period_and_company(): void
    {
        /** Arrange two companies, branches, employees, an active KPI period, and mixed orders. */
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $branchA = $this->createBranch($companyA, 'MAIN-A');
        $branchB = $this->createBranch($companyB, 'MAIN-B');

        $empA1 = $this->createEmployee($companyA, $branchA, 'EMP-A1');
        $empA2 = $this->createEmployee($companyA, $branchA, 'EMP-A2');
        $empB1 = $this->createEmployee($companyB, $branchB, 'EMP-B1');

        $period = KpiPeriod::query()->withoutGlobalScope('company')->create([
            'company_id' => $companyA->getKey(),
            'name' => 'November 2026',
            'period_type' => KpiPeriodType::Monthly,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'status' => KpiPeriodStatus::Active,
        ]);

        /** Included paid orders for empA1 within November 2026. */
        $this->createOrder($companyA, $branchA, $empA1, 'ORD-1', '125000.5000', PaymentStatus::Paid, OrderStatus::Completed, '2026-11-05 10:00:00');
        $this->createOrder($companyA, $branchA, $empA1, 'ORD-2', '274999.5000', PaymentStatus::Paid, OrderStatus::Pending, '2026-11-25 14:30:00');

        /** Excluded orders: unpaid, cancelled, out of period range, different employee, different company. */
        $this->createOrder($companyA, $branchA, $empA1, 'ORD-3', '500000.0000', PaymentStatus::Unpaid, OrderStatus::Pending, '2026-11-10 09:00:00');
        $this->createOrder($companyA, $branchA, $empA1, 'ORD-4', '300000.0000', PaymentStatus::Paid, OrderStatus::Cancelled, '2026-11-12 09:00:00');
        $this->createOrder($companyA, $branchA, $empA1, 'ORD-5', '999999.0000', PaymentStatus::Paid, OrderStatus::Completed, '2026-10-31 23:59:59');
        $this->createOrder($companyA, $branchA, $empA2, 'ORD-6', '200000.0000', PaymentStatus::Paid, OrderStatus::Completed, '2026-11-15 11:00:00');
        $this->createOrder($companyB, $branchB, $empB1, 'ORD-7', '800000.0000', PaymentStatus::Paid, OrderStatus::Completed, '2026-11-15 11:00:00');

        /** Act by resolving sales_revenue for empA1. */
        $revenue = app(KpiMetricSyncService::class)->resolveMetricValue(KpiMetricType::SalesRevenue, $empA1, $period);

        /** Assert exact bcmath sum of ORD-1 + ORD-2. */
        $this->assertSame('400000.0000', $revenue);
    }

    /**
     * Ensure attendance_rate and punctuality_rate accurately compute percentages and handle zero-record edge cases.
     */
    public function test_resolves_attendance_rate_and_punctuality_rate_from_hr_attendances(): void
    {
        /** Arrange employee with 4 attendance records (2 on-time, 1 late, 1 absent) and 1 out-of-range record. */
        $company = Company::factory()->create();
        $branch = $this->createBranch($company, 'MAIN');
        $employee = $this->createEmployee($company, $branch, 'EMP-01');
        $employeeNoLogs = $this->createEmployee($company, $branch, 'EMP-02');

        $period = KpiPeriod::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'name' => 'November 2026',
            'period_type' => KpiPeriodType::Monthly,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'status' => KpiPeriodStatus::Active,
        ]);

        $this->createAttendance($company, $branch, $employee, '2026-11-01', AttendanceStatus::Present, 0);
        $this->createAttendance($company, $branch, $employee, '2026-11-02', AttendanceStatus::Present, 0);
        $this->createAttendance($company, $branch, $employee, '2026-11-03', AttendanceStatus::Late, 12);
        $this->createAttendance($company, $branch, $employee, '2026-11-04', AttendanceStatus::Absent, 0);
        $this->createAttendance($company, $branch, $employee, '2026-10-20', AttendanceStatus::Present, 0);

        $syncService = app(KpiMetricSyncService::class);

        /** Attendance rate: 3 attended (2 present + 1 late) / 4 total = 75.0000%. */
        $this->assertSame('75.0000', $syncService->resolveMetricValue(KpiMetricType::AttendanceRate, $employee, $period));

        /** Punctuality rate: 2 on-time / 3 attended = 66.6666% (truncated at scale 4 after scale-8 division). */
        $this->assertSame('66.6666', $syncService->resolveMetricValue(KpiMetricType::PunctualityRate, $employee, $period));

        /** Zero records return 0.0000 safely without division by zero. */
        $this->assertSame('0.0000', $syncService->resolveMetricValue(KpiMetricType::AttendanceRate, $employeeNoLogs, $period));
        $this->assertSame('0.0000', $syncService->resolveMetricValue(KpiMetricType::PunctualityRate, $employeeNoLogs, $period));

        /** Manual metrics return null. */
        $this->assertNull($syncService->resolveMetricValue(KpiMetricType::ManualNumeric, $employee, $period));
        $this->assertNull($syncService->resolveMetricValue(KpiMetricType::ManualPercentage, $employee, $period));
    }

    /**
     * Ensure cross-company employee and period combinations throw InvalidArgumentException.
     */
    public function test_rejects_cross_company_employee_and_period_resolution(): void
    {
        /** Arrange employee in Company A and period in Company B. */
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $branchA = $this->createBranch($companyA, 'MAIN-A');
        $empA = $this->createEmployee($companyA, $branchA, 'EMP-A');

        $periodB = KpiPeriod::query()->withoutGlobalScope('company')->create([
            'company_id' => $companyB->getKey(),
            'name' => 'Period B',
            'period_type' => KpiPeriodType::Monthly,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'status' => KpiPeriodStatus::Active,
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(KpiMetricSyncService::class)->resolveMetricValue(KpiMetricType::SalesRevenue, $empA, $periodB);
    }

    /**
     * Create a test branch for the given company.
     */
    private function createBranch(Company $company, string $code): Branch
    {
        return Branch::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'code' => $code,
            'name' => "Branch {$code}",
            'is_main' => true,
        ]);
    }

    /**
     * Create an active test employee for the given company and branch.
     */
    private function createEmployee(Company $company, Branch $branch, string $code): Employee
    {
        return Employee::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'branch_id' => $branch->getKey(),
            'employee_code' => $code,
            'full_name' => "Employee {$code}",
            'ptkp_status' => PtkpStatus::Tk0,
            'base_salary' => '5000000.0000',
            'employment_status' => EmploymentStatus::Active,
            'join_date' => '2026-01-01',
        ]);
    }

    /**
     * Create a transaction order with explicit timestamps and payment/order status.
     */
    private function createOrder(
        Company $company,
        Branch $branch,
        Employee $salesperson,
        string $orderNumber,
        string $grandTotal,
        PaymentStatus $paymentStatus,
        OrderStatus $orderStatus,
        string $createdAt,
    ): void {
        $order = Order::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'branch_id' => $branch->getKey(),
            'salesperson_employee_id' => $salesperson->getKey(),
            'order_number' => $orderNumber,
            'channel' => OrderChannel::Pos,
            'order_status' => $orderStatus,
            'payment_status' => $paymentStatus,
            'subtotal' => $grandTotal,
            'discount_total' => '0.0000',
            'tax_total' => '0.0000',
            'grand_total' => $grandTotal,
        ]);

        $timestamp = Carbon::parse($createdAt);
        Order::query()->withoutGlobalScope('company')->whereKey($order->getKey())->update([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    /**
     * Create an attendance record for a given date and lateness profile.
     */
    private function createAttendance(
        Company $company,
        Branch $branch,
        Employee $employee,
        string $date,
        AttendanceStatus $status,
        int $lateMinutes,
    ): void {
        Attendance::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'branch_id' => $branch->getKey(),
            'employee_id' => $employee->getKey(),
            'attendance_date' => $date,
            'status' => $status,
            'late_minutes' => $lateMinutes,
            'overtime_minutes' => 0,
            'source' => AttendanceSource::Manual,
        ]);
    }
}
