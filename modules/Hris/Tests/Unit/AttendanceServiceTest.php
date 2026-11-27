<?php

declare(strict_types=1);

namespace Modules\Hris\Tests\Unit;

use App\Models\Company;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Core\Models\Branch;
use Modules\Hris\Enums\AttendanceSource;
use Modules\Hris\Enums\AttendanceStatus;
use Modules\Hris\Enums\EmploymentStatus;
use Modules\Hris\Enums\PtkpStatus;
use Modules\Hris\Models\Employee;
use Modules\Hris\Models\WorkShift;
use Modules\Hris\Services\AttendanceService;
use Modules\Iot\Enums\IotDeviceStatus;
use Modules\Iot\Enums\IotDeviceType;
use Modules\Iot\Models\IotDevice;
use Tests\TestCase;

/**
 * Unit tests verifying AttendanceService clock-in lateness tolerance, overtime calculation, and IoT readiness.
 */
class AttendanceServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure clock-in within shift tolerance records present status with zero late minutes.
     */
    public function test_clock_in_within_tolerance_marks_present_with_zero_late_minutes(): void
    {
        /** Arrange a company, shift (08:00-17:00, 15m tolerance), and active employee. */
        [$company, $branch, $shift, $employee] = $this->createEmployeeFixture();
        $service = app(AttendanceService::class);

        /** Act by clocking in 10 minutes past 08:00 (inside 15-minute tolerance). */
        $attendance = $service->recordClockIn(
            employee: $employee,
            clockInAt: '2026-04-10 08:10:00',
            source: AttendanceSource::Web,
            branch: $branch,
            shift: $shift,
        );

        /** Assert attendance is present with 0 late minutes. */
        $this->assertSame(AttendanceStatus::Present, $attendance->status);
        $this->assertSame(0, $attendance->late_minutes);
        $this->assertSame(AttendanceSource::Web, $attendance->source);
    }

    /**
     * Ensure clock-in exceeding tolerance marks late status and records exact late minutes, and clock-out computes overtime.
     */
    public function test_late_clock_in_and_overtime_clock_out_via_iot_esp32_source(): void
    {
        /** Arrange an employee assigned to 08:00-17:00 shift with 15-minute tolerance and an IoT device. */
        [$company, $branch, $shift, $employee] = $this->createEmployeeFixture();
        $iotDevice = IotDevice::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'branch_id' => $branch->getKey(),
            'device_code' => 'ESP32-TEST-77',
            'name' => 'Test Terminal',
            'device_type' => IotDeviceType::AttendanceRfid,
            'api_token_hash' => hash('sha256', 'iot_test_token_77'),
            'token_prefix' => 'iot_test_tok',
            'status' => IotDeviceStatus::Active,
            'cooldown_seconds' => 60,
        ]);
        $service = app(AttendanceService::class);

        /** Act by clocking in 25 minutes late and clocking out 90 minutes past shift end via ESP32 source. */
        $clockInAttendance = $service->recordClockIn(
            employee: $employee,
            clockInAt: '2026-04-11 08:25:00',
            source: AttendanceSource::IotEsp32,
            iotDeviceId: $iotDevice->getKey(),
        );

        $clockOutAttendance = $service->recordClockOut(
            employee: $employee,
            clockOutAt: '2026-04-11 18:30:00',
            source: AttendanceSource::IotEsp32,
            iotDeviceId: $iotDevice->getKey(),
        );

        /** Assert lateness, overtime, and nullable iot_device_id stored accurately. */
        $this->assertSame(AttendanceStatus::Late, $clockInAttendance->status);
        $this->assertSame(25, $clockInAttendance->late_minutes);
        $this->assertSame(90, $clockOutAttendance->overtime_minutes);
        $this->assertSame($iotDevice->getKey(), $clockOutAttendance->iot_device_id);
        $this->assertSame(AttendanceSource::IotEsp32, $clockOutAttendance->source);
    }

    /**
     * Ensure overnight shift clock-out on the next calendar morning matches previous day's attendance.
     */
    public function test_overnight_shift_clock_out_calculates_overtime_across_midnight(): void
    {
        /** Arrange an overnight shift (22:00 to 06:00 next day). */
        $company = Company::factory()->create();
        $shift = WorkShift::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'name' => 'Night Shift',
            'clock_in_time' => '22:00:00',
            'clock_out_time' => '06:00:00',
            'late_tolerance_minutes' => 15,
            'is_overnight' => true,
        ]);

        $employee = Employee::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'work_shift_id' => $shift->getKey(),
            'employee_code' => 'EMP-NIGHT-1',
            'full_name' => 'Night Guard',
            'ptkp_status' => PtkpStatus::Tk0,
            'base_salary' => '4000000.0000',
            'employment_status' => EmploymentStatus::Active,
            'join_date' => '2026-01-01',
        ]);

        $service = app(AttendanceService::class);
        $service->recordClockIn($employee, '2026-04-12 21:55:00');

        /** Act by clocking out at 06:45 the following morning. */
        $attendance = $service->recordClockOut($employee, '2026-04-13 06:45:00');

        /** Assert 45 minutes overtime against the 06:00 overnight boundary. */
        $this->assertSame('2026-04-12', $attendance->attendance_date->toDateString());
        $this->assertSame(45, $attendance->overtime_minutes);
    }

    /**
     * Ensure cross-tenant branches and invalid clock-out sequences throw explicit domain exceptions.
     */
    public function test_rejects_cross_company_branch_and_clock_out_without_clock_in(): void
    {
        /** Arrange two separate tenant companies. */
        [$companyA, , , $employeeA] = $this->createEmployeeFixture();
        $companyB = Company::factory()->create();
        $foreignBranch = Branch::query()->withoutGlobalScope('company')->create([
            'company_id' => $companyB->getKey(),
            'code' => 'FOREIGN',
            'name' => 'Foreign Branch',
            'is_main' => true,
        ]);

        $service = app(AttendanceService::class);

        try {
            $service->recordClockIn($employeeA, '2026-04-15 08:00:00', AttendanceSource::Manual, $foreignBranch);
            $this->fail('Expected InvalidArgumentException for cross-company branch.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $this->expectException(DomainException::class);
        $service->recordClockOut($employeeA, '2026-04-15 17:00:00');
    }

    /**
     * Build a reusable company, branch, shift, and active employee fixture.
     *
     * @return array{0: Company, 1: Branch, 2: WorkShift, 3: Employee}
     */
    private function createEmployeeFixture(): array
    {
        $company = Company::factory()->create();
        $branch = Branch::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'code' => 'MAIN',
            'name' => 'Main Branch',
            'is_main' => true,
        ]);
        $shift = WorkShift::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'name' => 'Regular Morning',
            'clock_in_time' => '08:00:00',
            'clock_out_time' => '17:00:00',
            'late_tolerance_minutes' => 15,
            'is_overnight' => false,
        ]);
        $employee = Employee::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'branch_id' => $branch->getKey(),
            'work_shift_id' => $shift->getKey(),
            'employee_code' => 'EMP-001',
            'full_name' => 'Budi Karyawan',
            'ptkp_status' => PtkpStatus::Tk0,
            'base_salary' => '6000000.0000',
            'employment_status' => EmploymentStatus::Active,
            'join_date' => '2026-01-01',
        ]);

        return [$company, $branch, $shift, $employee];
    }
}
