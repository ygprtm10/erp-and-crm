<?php

declare(strict_types=1);

namespace Modules\Hris\Tests\Unit;

use App\Models\Company;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Hris\Enums\AttendanceStatus;
use Modules\Hris\Enums\EmploymentStatus;
use Modules\Hris\Enums\LeaveStatus;
use Modules\Hris\Enums\LeaveType;
use Modules\Hris\Enums\PtkpStatus;
use Modules\Hris\Models\Attendance;
use Modules\Hris\Models\Employee;
use Modules\Hris\Services\LeaveRequestService;
use Tests\TestCase;

/**
 * Unit tests verifying LeaveRequestService date-range attendance synchronization and rejection rules.
 */
class LeaveRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure approving annual or sick leave populates hr_attendances for every date in the range.
     */
    public function test_approve_leave_populates_attendance_rows_across_date_range(): void
    {
        /** Arrange an active employee, approver user, and 3-day sick leave request. */
        [$company, $employee, $approver] = $this->createFixture();
        $service = app(LeaveRequestService::class);

        $leave = $service->submitLeave(
            employee: $employee,
            type: LeaveType::Sick,
            startDate: '2026-04-06',
            endDate: '2026-04-08',
            reason: 'Medical recovery',
        );

        $this->assertSame(3, $leave->total_days);
        $this->assertSame(LeaveStatus::Pending, $leave->status);

        /** Act by approving the leave request. */
        $approved = $service->approveLeave($leave, $approver);

        /** Assert 3 hr_attendances rows exist with Sick status and approver metadata saved. */
        $this->assertSame(LeaveStatus::Approved, $approved->status);
        $this->assertSame($approver->getKey(), $approved->approved_by);

        $attendances = Attendance::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->where('employee_id', $employee->getKey())
            ->orderBy('attendance_date')
            ->get();

        $this->assertCount(3, $attendances);
        $this->assertSame(
            ['2026-04-06', '2026-04-07', '2026-04-08'],
            $attendances->map(static fn (Attendance $row): string => $row->attendance_date->toDateString())->all(),
        );
        $this->assertTrue($attendances->every(static fn (Attendance $row): bool => $row->status === AttendanceStatus::Sick));
    }

    /**
     * Ensure rejecting a leave request records rejection reason and creates zero attendance rows.
     */
    public function test_reject_leave_does_not_create_attendance_rows(): void
    {
        /** Arrange a pending annual leave request. */
        [$company, $employee, $approver] = $this->createFixture();
        $service = app(LeaveRequestService::class);

        $leave = $service->submitLeave(
            employee: $employee,
            type: LeaveType::Annual,
            startDate: '2026-04-20',
            endDate: '2026-04-21',
            reason: 'Family holiday',
        );

        /** Act by rejecting the leave request. */
        $rejected = $service->rejectLeave($leave, $approver, 'Peak retail schedule');

        /** Assert status is Rejected with zero attendance records created. */
        $this->assertSame(LeaveStatus::Rejected, $rejected->status);
        $this->assertSame('Peak retail schedule', $rejected->rejection_reason);
        $this->assertSame(
            0,
            Attendance::query()->withoutGlobalScope('company')->where('employee_id', $employee->getKey())->count(),
        );

        $this->expectException(DomainException::class);
        $service->approveLeave($rejected, $approver);
    }

    /**
     * Ensure invalid date ranges and empty rejection reasons are rejected immediately.
     */
    public function test_validates_date_order_and_required_rejection_reason(): void
    {
        /** Arrange an employee and approver. */
        [, $employee, $approver] = $this->createFixture();
        $service = app(LeaveRequestService::class);

        try {
            $service->submitLeave($employee, LeaveType::Annual, '2026-04-10', '2026-04-08', 'Invalid dates');
            $this->fail('Expected InvalidArgumentException when end date precedes start date.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $leave = $service->submitLeave($employee, LeaveType::Permission, '2026-04-10', '2026-04-10', 'Permit');

        $this->expectException(InvalidArgumentException::class);
        $service->rejectLeave($leave, $approver, '   ');
    }

    /**
     * Create a reusable company, employee, and approver user fixture.
     *
     * @return array{0: Company, 1: Employee, 2: User}
     */
    private function createFixture(): array
    {
        $company = Company::factory()->create();
        $approver = User::factory()->create();
        $employee = Employee::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'employee_code' => 'EMP-LV-01',
            'full_name' => 'Siti Leave',
            'ptkp_status' => PtkpStatus::Tk0,
            'base_salary' => '5000000.0000',
            'employment_status' => EmploymentStatus::Active,
            'join_date' => '2026-01-01',
        ]);

        return [$company, $employee, $approver];
    }
}
