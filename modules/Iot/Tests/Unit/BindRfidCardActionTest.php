<?php

declare(strict_types=1);

namespace Modules\Iot\Tests\Unit;

use App\Models\Company;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Hris\Enums\EmploymentStatus;
use Modules\Hris\Enums\PtkpStatus;
use Modules\Hris\Models\Employee;
use Modules\Iot\Actions\BindRfidCardAction;
use Modules\Iot\Enums\IotLogStatus;
use Modules\Iot\Enums\IotScanType;
use Modules\Iot\Models\IotAttendanceLog;
use Modules\Iot\Services\DeviceProvisioningService;
use Tests\TestCase;

/**
 * Unit tests verifying BindRfidCardAction validation, duplicate prevention, and tenant boundaries.
 */
class BindRfidCardActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure an unregistered UID log can be bound to an employee in the same company.
     */
    public function test_binds_unregistered_uid_to_employee_within_same_company(): void
    {
        /** Arrange a company, device, unregistered scan log, and employee without an RFID UID. */
        $company = Company::factory()->create();
        $device = app(DeviceProvisioningService::class)->registerDevice($company, 'ESP32-BIND-1', 'Front Desk')->device;

        $employee = Employee::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'employee_code' => 'EMP-BIND-1',
            'full_name' => 'Siti Aminah',
            'ptkp_status' => PtkpStatus::Tk0,
            'base_salary' => '5000000.0000',
            'employment_status' => EmploymentStatus::Active,
            'join_date' => '2026-01-01',
        ]);

        $log = IotAttendanceLog::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'iot_device_id' => $device->getKey(),
            'scanned_uid' => 'UID-SITI-01',
            'scan_type' => IotScanType::Unknown,
            'status' => IotLogStatus::UnregisteredUid,
            'processed_at' => now(),
        ]);

        /** Act by executing BindRfidCardAction. */
        $updated = app(BindRfidCardAction::class)->execute($log, $employee);

        /** Assert employee rfid_uid is updated cleanly. */
        $this->assertSame('UID-SITI-01', $updated->rfid_uid);
    }

    /**
     * Ensure binding fails when another employee in the same company already owns the scanned UID.
     */
    public function test_rejects_binding_uid_already_assigned_to_another_employee(): void
    {
        /** Arrange two employees in the same company where one already holds the RFID UID. */
        $company = Company::factory()->create();
        $device = app(DeviceProvisioningService::class)->registerDevice($company, 'ESP32-BIND-2', 'Front Desk')->device;

        Employee::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'employee_code' => 'EMP-EXISTING',
            'full_name' => 'Existing Holder',
            'rfid_uid' => 'UID-DUP-01',
            'ptkp_status' => PtkpStatus::Tk0,
            'base_salary' => '5000000.0000',
            'employment_status' => EmploymentStatus::Active,
            'join_date' => '2026-01-01',
        ]);

        $targetEmployee = Employee::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'employee_code' => 'EMP-TARGET',
            'full_name' => 'Target Employee',
            'ptkp_status' => PtkpStatus::Tk0,
            'base_salary' => '5000000.0000',
            'employment_status' => EmploymentStatus::Active,
            'join_date' => '2026-01-01',
        ]);

        $log = IotAttendanceLog::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'iot_device_id' => $device->getKey(),
            'scanned_uid' => 'UID-DUP-01',
            'scan_type' => IotScanType::Unknown,
            'status' => IotLogStatus::UnregisteredUid,
            'processed_at' => now(),
        ]);

        $this->expectException(DomainException::class);

        app(BindRfidCardAction::class)->execute($log, $targetEmployee);
    }

    /**
     * Ensure binding rejects cross-tenant logs and non-unregistered log statuses.
     */
    public function test_rejects_cross_company_binding_and_processed_log_status(): void
    {
        /** Arrange two companies, an unregistered log in company A, and an employee in company B. */
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $deviceA = app(DeviceProvisioningService::class)->registerDevice($companyA, 'ESP32-A', 'Terminal A')->device;

        $employeeA = Employee::query()->withoutGlobalScope('company')->create([
            'company_id' => $companyA->getKey(),
            'employee_code' => 'EMP-A',
            'full_name' => 'Alpha User',
            'ptkp_status' => PtkpStatus::Tk0,
            'base_salary' => '5000000.0000',
            'employment_status' => EmploymentStatus::Active,
            'join_date' => '2026-01-01',
        ]);

        $employeeB = Employee::query()->withoutGlobalScope('company')->create([
            'company_id' => $companyB->getKey(),
            'employee_code' => 'EMP-B',
            'full_name' => 'Beta User',
            'ptkp_status' => PtkpStatus::Tk0,
            'base_salary' => '5000000.0000',
            'employment_status' => EmploymentStatus::Active,
            'join_date' => '2026-01-01',
        ]);

        $unregisteredLog = IotAttendanceLog::query()->withoutGlobalScope('company')->create([
            'company_id' => $companyA->getKey(),
            'iot_device_id' => $deviceA->getKey(),
            'scanned_uid' => 'UID-CROSS-01',
            'scan_type' => IotScanType::Unknown,
            'status' => IotLogStatus::UnregisteredUid,
            'processed_at' => now(),
        ]);

        $processedLog = IotAttendanceLog::query()->withoutGlobalScope('company')->create([
            'company_id' => $companyA->getKey(),
            'iot_device_id' => $deviceA->getKey(),
            'employee_id' => $employeeA->getKey(),
            'scanned_uid' => 'UID-DONE-01',
            'scan_type' => IotScanType::ClockIn,
            'status' => IotLogStatus::Processed,
            'processed_at' => now(),
        ]);

        $action = app(BindRfidCardAction::class);

        try {
            $action->execute($unregisteredLog, $employeeB);
            $this->fail('Expected InvalidArgumentException for cross-company binding.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $this->expectException(DomainException::class);
        $action->execute($processedLog, $employeeA);
    }
}
