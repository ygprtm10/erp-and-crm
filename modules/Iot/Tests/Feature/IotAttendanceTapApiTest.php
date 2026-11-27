<?php

declare(strict_types=1);

namespace Modules\Iot\Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Branch;
use Modules\Hris\Enums\AttendanceSource;
use Modules\Hris\Enums\AttendanceStatus;
use Modules\Hris\Enums\EmploymentStatus;
use Modules\Hris\Enums\PtkpStatus;
use Modules\Hris\Models\Attendance;
use Modules\Hris\Models\Employee;
use Modules\Hris\Models\WorkShift;
use Modules\Iot\Enums\IotLogStatus;
use Modules\Iot\Enums\IotScanType;
use Modules\Iot\Models\IotAttendanceLog;
use Modules\Iot\Models\IotDevice;
use Modules\Iot\Services\DeviceProvisioningService;
use Modules\Platform\Actions\ActivateCompanyModulesAction;
use Modules\Platform\Database\Seeders\SaasModuleSeeder;
use Modules\Platform\Enums\CompanyModuleStatus;
use Modules\Platform\Models\SaasModule;
use Tests\TestCase;

/**
 * Feature tests verifying ESP32 attendance tap ingestion, cooldown debounce, clock-in/out, and tenant isolation.
 */
class IotAttendanceTapApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure tap 1 records clock-in, tap inside cooldown is ignored, and tap after cooldown records clock-out with overtime.
     */
    public function test_tap_lifecycle_handles_clock_in_cooldown_debounce_clock_out_and_already_completed(): void
    {
        /** Arrange Toko Maju fixture with 08:00-17:00 shift, active employee, and ESP32 device (60s cooldown). */
        [$company, $device, $plainToken, $employee] = $this->createTapFixture();

        /** Act 1: Tap at 08:20 (late by 20 mins past 15-min tolerance) -> Clock-In. */
        $tapOneResponse = $this->withToken($plainToken)->postJson('/api/v1/iot/attendance/tap', [
            'uid' => 'UID-KARYAWAN-01',
            'device_timestamp' => '2026-05-12 08:20:00',
        ]);

        $tapOneResponse->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('code', 'CLOCK_IN')
            ->assertJsonPath('scan_type', IotScanType::ClockIn->value)
            ->assertJsonPath('employee_name', 'Rina Wijaya')
            ->assertJsonPath('late_minutes', 20);

        $attendance = Attendance::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->where('employee_id', $employee->getKey())
            ->firstOrFail();

        $this->assertSame(AttendanceSource::IotEsp32, $attendance->source);
        $this->assertSame($device->getKey(), $attendance->iot_device_id);
        $this->assertSame(AttendanceStatus::Late, $attendance->status);
        $this->assertSame(20, $attendance->late_minutes);
        $this->assertNull($attendance->clock_out);

        /** Act 2: Repeat tap 30 seconds later (within 60s cooldown) -> Ignored without touching attendance. */
        $tapTwoResponse = $this->withToken($plainToken)->postJson('/api/v1/iot/attendance/tap', [
            'uid' => 'UID-KARYAWAN-01',
            'device_timestamp' => '2026-05-12 08:20:30',
        ]);

        $tapTwoResponse->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('code', 'COOLDOWN_IGNORED')
            ->assertJsonPath('scan_type', IotScanType::Ignored->value);

        $attendance->refresh();
        $this->assertNull($attendance->clock_out);

        /** Act 3: Tap at 18:15 (after cooldown & 75 mins past 17:00 shift end) -> Clock-Out with overtime. */
        $tapThreeResponse = $this->withToken($plainToken)->postJson('/api/v1/iot/attendance/tap', [
            'uid' => 'UID-KARYAWAN-01',
            'device_timestamp' => '2026-05-12 18:15:00',
        ]);

        $tapThreeResponse->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('code', 'CLOCK_OUT')
            ->assertJsonPath('scan_type', IotScanType::ClockOut->value)
            ->assertJsonPath('overtime_minutes', 75);

        $attendance->refresh();
        $this->assertNotNull($attendance->clock_out);
        $this->assertSame(75, $attendance->overtime_minutes);

        /** Act 4: Tap again at 18:25 after both clock-in and clock-out are complete -> ALREADY_COMPLETED. */
        $tapFourResponse = $this->withToken($plainToken)->postJson('/api/v1/iot/attendance/tap', [
            'uid' => 'UID-KARYAWAN-01',
            'device_timestamp' => '2026-05-12 18:25:00',
        ]);

        $tapFourResponse->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('code', 'ALREADY_COMPLETED')
            ->assertJsonPath('scan_type', IotScanType::Ignored->value);

        /** Assert 4 audit logs exist with exact statuses. */
        $logs = IotAttendanceLog::query()
            ->withoutGlobalScope('company')
            ->where('iot_device_id', $device->getKey())
            ->orderBy('id')
            ->get();

        $this->assertCount(4, $logs);
        $this->assertSame(IotLogStatus::Processed, $logs[0]->status);
        $this->assertSame(IotLogStatus::IgnoredCooldown, $logs[1]->status);
        $this->assertSame(IotLogStatus::Processed, $logs[2]->status);
        $this->assertSame(IotLogStatus::IgnoredCooldown, $logs[3]->status);
    }

    /**
     * Ensure unknown RFID UIDs are logged as unregistered_uid and return an OLED-friendly error payload.
     */
    public function test_unregistered_uid_creates_unregistered_log_and_returns_oled_message(): void
    {
        /** Arrange a provisioned device with active modules. */
        [$company, $device, $plainToken] = $this->createTapFixture();

        /** Act by tapping an unknown RFID UID. */
        $response = $this->withToken($plainToken)->postJson('/api/v1/iot/attendance/tap', [
            'uid' => 'UID-UNKNOWN-888',
        ]);

        /** Assert compact ok=false payload and unregistered_uid log entry. */
        $response->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'UNREGISTERED_UID')
            ->assertJsonPath('scan_type', IotScanType::Unknown->value)
            ->assertJsonPath('display_line_1', 'Kartu Tidak Terdaftar')
            ->assertJsonPath('display_line_2', 'UID-UNKNOWN-888');

        $log = IotAttendanceLog::query()
            ->withoutGlobalScope('company')
            ->where('iot_device_id', $device->getKey())
            ->firstOrFail();

        $this->assertSame(IotLogStatus::UnregisteredUid, $log->status);
        $this->assertSame(IotScanType::Unknown, $log->scan_type);
        $this->assertNull($log->employee_id);
        $this->assertNull($log->attendance_id);
        $this->assertSame(0, Attendance::query()->withoutGlobalScope('company')->count());
    }

    /**
     * Ensure cross-tenant isolation rejects an employee UID owned by another company as unregistered_uid.
     */
    public function test_cross_tenant_employee_uid_is_rejected_as_unregistered_uid(): void
    {
        /** Arrange Toko Maju device and a Gym Sehat employee with a known RFID UID. */
        [$tokoMaju, $tokoDevice, $tokoPlainToken] = $this->createTapFixture();

        $gymSehat = Company::factory()->create(['slug' => 'gym-sehat-isolated']);
        $gymEmployee = Employee::query()->withoutGlobalScope('company')->create([
            'company_id' => $gymSehat->getKey(),
            'employee_code' => 'GYM-EMP-01',
            'full_name' => 'Gym Trainer',
            'rfid_uid' => 'UID-GYM-SECRET',
            'ptkp_status' => PtkpStatus::Tk0,
            'base_salary' => '5000000.0000',
            'employment_status' => EmploymentStatus::Active,
            'join_date' => '2026-01-01',
        ]);

        /** Act by tapping Gym Sehat employee's RFID UID on Toko Maju's ESP32 device. */
        $response = $this->withToken($tokoPlainToken)->postJson('/api/v1/iot/attendance/tap', [
            'uid' => 'UID-GYM-SECRET',
        ]);

        /** Assert strict tenant isolation: rejected as UNREGISTERED_UID with zero attendance mutations. */
        $response->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'UNREGISTERED_UID');

        $log = IotAttendanceLog::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $tokoMaju->getKey())
            ->firstOrFail();

        $this->assertSame(IotLogStatus::UnregisteredUid, $log->status);
        $this->assertNull($log->employee_id);
        $this->assertSame(0, Attendance::query()->withoutGlobalScope('company')->where('employee_id', $gymEmployee->getKey())->count());
    }

    /**
     * Ensure tap request validation rejects missing UID payloads with 422 status.
     */
    public function test_tap_request_requires_uid_string(): void
    {
        /** Arrange a provisioned device with active modules. */
        [, , $plainToken] = $this->createTapFixture();

        $this->withToken($plainToken)
            ->postJson('/api/v1/iot/attendance/tap', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['uid']);
    }

    /**
     * Build a complete company, active modules, branch, shift, employee, and provisioned ESP32 device fixture.
     *
     * @return array{0: Company, 1: IotDevice, 2: string, 3: Employee}
     */
    private function createTapFixture(): array
    {
        $this->seed(SaasModuleSeeder::class);
        $company = Company::factory()->create();

        $moduleIds = SaasModule::query()
            ->whereIn('slug', ['iot', 'hris'])
            ->pluck('id')
            ->all();

        app(ActivateCompanyModulesAction::class)->execute(
            company: $company,
            moduleIds: $moduleIds,
            expiresAt: now()->addYear(),
            status: CompanyModuleStatus::Active,
        );

        $branch = Branch::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'code' => 'MAIN',
            'name' => 'Main Branch',
            'is_main' => true,
        ]);

        $shift = WorkShift::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'name' => 'Shift Pagi',
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
            'full_name' => 'Rina Wijaya',
            'rfid_uid' => 'UID-KARYAWAN-01',
            'ptkp_status' => PtkpStatus::Tk0,
            'base_salary' => '8000000.0000',
            'employment_status' => EmploymentStatus::Active,
            'join_date' => '2026-01-01',
        ]);

        $provisioned = app(DeviceProvisioningService::class)->registerDevice(
            company: $company,
            deviceCode: 'ESP32-TAP-01',
            name: 'Lobby Scanner',
            branch: $branch,
            cooldownSeconds: 60,
        );

        return [$company, $provisioned->device, $provisioned->plainToken, $employee];
    }
}
