<?php

declare(strict_types=1);

namespace Modules\Iot\Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\Branch;
use Modules\Hris\Enums\AttendanceSource;
use Modules\Hris\Models\Attendance;
use Modules\Hris\Models\Employee;
use Modules\Iot\Enums\IotDeviceStatus;
use Modules\Iot\Enums\IotDeviceType;
use Modules\Iot\Enums\IotLogStatus;
use Modules\Iot\Enums\IotScanType;
use Modules\Iot\Filament\Resources\IotAttendanceLogResource\Pages\ListIotAttendanceLogs;
use Modules\Iot\Filament\Resources\IotDeviceResource\Pages\CreateIotDevice;
use Modules\Iot\Filament\Resources\IotDeviceResource\Pages\ListIotDevices;
use Modules\Iot\Models\IotAttendanceLog;
use Modules\Iot\Models\IotDevice;
use Tests\TestCase;

/**
 * Feature tests verifying Filament IoT device provisioning, token rotation, tap simulation, and RFID binding.
 */
class IotFilamentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure CreateIotDevice provisions a device with SHA-256 hashed token and sends a one-time notification.
     */
    public function test_create_iot_device_hashes_token_and_sends_notification(): void
    {
        /** Arrange seeded Toko Maju tenant and owner user. */
        $this->seed(DatabaseSeeder::class);

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $branch = Branch::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->firstOrFail();

        $this->actingAs($owner1);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tokoMaju, isQuiet: true);

        /** Act by creating a new ESP32 device via the Filament CreateIotDevice page. */
        Livewire::test(CreateIotDevice::class)
            ->fillForm([
                'device_code' => 'ESP32-KASIR-02',
                'name' => 'Terminal Kasir 2',
                'branch_id' => $branch->getKey(),
                'device_type' => IotDeviceType::AttendanceHybrid->value,
                'cooldown_seconds' => 30,
                'status' => IotDeviceStatus::Active->value,
                'firmware_version' => '1.1.0',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Perangkat ESP32 Berhasil Didaftarkan');

        /** Assert database stores SHA-256 hash and token prefix rather than plaintext. */
        $created = IotDevice::query()
            ->where('company_id', $tokoMaju->getKey())
            ->where('device_code', 'ESP32-KASIR-02')
            ->firstOrFail();

        $this->assertSame(64, strlen($created->api_token_hash));
        $this->assertStringStartsWith('iot_', $created->token_prefix);
        $this->assertSame(30, $created->cooldown_seconds);
    }

    /**
     * Ensure Rotate Token API action updates api_token_hash and Simulasi Tap Kartu writes log and attendance.
     */
    public function test_rotate_token_and_simulate_tap_actions_work_on_iot_device_resource(): void
    {
        /** Arrange seeded Toko Maju device and EMP-001 employee. */
        $this->seed(DatabaseSeeder::class);

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $device = IotDevice::query()->withoutGlobalScope('company')
            ->where('company_id', $tokoMaju->getKey())
            ->where('device_code', 'ESP32-PUSAT-01')
            ->firstOrFail();
        $employee = Employee::query()->withoutGlobalScope('company')
            ->where('company_id', $tokoMaju->getKey())
            ->where('employee_code', 'EMP-001')
            ->firstOrFail();

        $oldHash = $device->api_token_hash;

        $this->actingAs($owner1);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tokoMaju, isQuiet: true);

        /** Act 1: Rotate API token via Table Action. */
        Livewire::test(ListIotDevices::class)
            ->callAction(TestAction::make('rotateToken')->table($device))
            ->assertHasNoActionErrors()
            ->assertNotified('Token API Baru Berhasil Dibuat');

        $device->refresh();
        $this->assertNotSame($oldHash, $device->api_token_hash);

        /** Act 2: Simulate an RFID tap for EMP-001 on a fresh date via Table Action. */
        $simulatedDate = now()->startOfMonth()->addDays(6)->setTime(7, 50);

        Livewire::test(ListIotDevices::class)
            ->callAction(TestAction::make('simulateTap')->table($device), data: [
                'scanned_uid' => $employee->rfid_uid,
                'device_timestamp' => $simulatedDate->format('Y-m-d H:i:s'),
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Absen Masuk OK');

        /** Assert both hr_attendances and iot_attendance_logs were created for the simulated tap. */
        $attendance = Attendance::query()
            ->where('company_id', $tokoMaju->getKey())
            ->where('employee_id', $employee->getKey())
            ->whereDate('attendance_date', $simulatedDate->toDateString())
            ->firstOrFail();

        $this->assertSame(AttendanceSource::IotEsp32, $attendance->source);
        $this->assertSame($device->getKey(), $attendance->iot_device_id);

        $log = IotAttendanceLog::query()
            ->where('company_id', $tokoMaju->getKey())
            ->where('iot_device_id', $device->getKey())
            ->where('attendance_id', $attendance->getKey())
            ->firstOrFail();

        $this->assertSame(IotScanType::ClockIn, $log->scan_type);
        $this->assertSame(IotLogStatus::Processed, $log->status);
    }

    /**
     * Ensure Tautkan ke Karyawan action is visible only on unregistered_uid logs and updates employee rfid_uid.
     */
    public function test_bind_employee_action_visibility_and_updates_employee_rfid_uid(): void
    {
        /** Arrange seeded Toko Maju with one processed log, one unregistered log, and EMP-002. */
        $this->seed(DatabaseSeeder::class);

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();

        $processedLog = IotAttendanceLog::query()->withoutGlobalScope('company')
            ->where('company_id', $tokoMaju->getKey())
            ->where('status', IotLogStatus::Processed->value)
            ->firstOrFail();

        $unregisteredLog = IotAttendanceLog::query()->withoutGlobalScope('company')
            ->where('company_id', $tokoMaju->getKey())
            ->where('status', IotLogStatus::UnregisteredUid->value)
            ->firstOrFail();

        $empTwo = Employee::query()->withoutGlobalScope('company')
            ->where('company_id', $tokoMaju->getKey())
            ->where('employee_code', 'EMP-002')
            ->firstOrFail();

        $this->actingAs($owner1);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tokoMaju, isQuiet: true);

        /** Assert action is hidden on processed logs, visible on unregistered logs, and updates rfid_uid. */
        Livewire::test(ListIotAttendanceLogs::class)
            ->assertActionHidden(TestAction::make('bindEmployee')->table($processedLog))
            ->assertActionVisible(TestAction::make('bindEmployee')->table($unregisteredLog))
            ->callAction(TestAction::make('bindEmployee')->table($unregisteredLog), data: [
                'employee_id' => $empTwo->getKey(),
            ])
            ->assertHasNoActionErrors();

        $this->assertSame($unregisteredLog->scanned_uid, $empTwo->refresh()->rfid_uid);
    }
}
