<?php

declare(strict_types=1);

namespace Modules\Iot\Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;
use Modules\Core\Models\Branch;
use Modules\Hris\Models\Attendance;
use Modules\Hris\Models\Employee;
use Modules\Iot\Enums\IotDeviceStatus;
use Modules\Iot\Enums\IotDeviceType;
use Modules\Iot\Enums\IotLogStatus;
use Modules\Iot\Enums\IotScanType;
use Modules\Iot\Models\IotAttendanceLog;
use Modules\Iot\Models\IotDevice;
use Modules\Platform\Actions\ActivateCompanyModulesAction;
use Modules\Platform\Enums\CompanyModuleStatus;
use Modules\Platform\Models\SaasModule;

/**
 * Idempotently activates IoT for Toko Maju and seeds an ESP32 demo device, employee RFID UIDs, and sample logs.
 */
class IotDemoSeeder extends Seeder
{
    /**
     * Deterministic demo plaintext token for local/testing ESP32 curl and hardware tests.
     */
    public const string DEMO_PLAIN_TOKEN = 'iot_demo_toko_maju_esp32_token';

    /**
     * Run the database seeds in local/testing environments.
     */
    public function run(): void
    {
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->first();

        if (! $tokoMaju instanceof Company) {
            return;
        }

        /** Activate IoT strictly for Toko Maju so Gym Sehat remains unsubscribed for RequiresModule checks. */
        $iotModule = SaasModule::query()->where('slug', 'iot')->first();

        if ($iotModule instanceof SaasModule) {
            app(ActivateCompanyModulesAction::class)->execute(
                company: $tokoMaju,
                moduleIds: [$iotModule->getKey()],
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

        /** Assign deterministic RFID card UIDs to Toko Maju demo employees. */
        $empOne = Employee::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('employee_code', 'EMP-001')
            ->first();

        if ($empOne instanceof Employee) {
            $empOne->update(['rfid_uid' => 'UID-EMP-001']);
        }

        $empTwo = Employee::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('employee_code', 'EMP-002')
            ->first();

        if ($empTwo instanceof Employee) {
            $empTwo->update(['rfid_uid' => 'UID-EMP-002']);
        }

        /** Upsert the deterministic local demo ESP32 device storing only the SHA-256 hash. */
        $device = IotDevice::query()
            ->withoutGlobalScope('company')
            ->updateOrCreate(
                [
                    'company_id' => $companyId,
                    'device_code' => 'ESP32-PUSAT-01',
                ],
                [
                    'branch_id' => $mainBranch?->getKey(),
                    'name' => 'ESP32 Lobby Pusat',
                    'device_type' => IotDeviceType::AttendanceRfid,
                    'api_token_hash' => hash('sha256', self::DEMO_PLAIN_TOKEN),
                    'token_prefix' => substr(self::DEMO_PLAIN_TOKEN, 0, 12),
                    'firmware_version' => '1.0.0',
                    'mac_address' => 'AA:BB:CC:DD:EE:01',
                    'last_ip_address' => '127.0.0.1',
                    'status' => IotDeviceStatus::Active,
                    'cooldown_seconds' => 60,
                    'last_heartbeat_at' => now(),
                    'notes' => 'Demo ESP32 attendance terminal for Toko Maju main branch',
                ],
            );

        /** Seed two sample logs idempotently (one processed and one unregistered UID for card binding testing). */
        $hasLogs = IotAttendanceLog::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('iot_device_id', $device->getKey())
            ->exists();

        if ($hasLogs) {
            return;
        }

        $empOneAttendance = $empOne instanceof Employee
            ? Attendance::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->where('employee_id', $empOne->getKey())
                ->latest('attendance_date')
                ->first()
            : null;

        $processedAt = now()->subMinutes(15);

        IotAttendanceLog::query()
            ->withoutGlobalScope('company')
            ->create([
                'company_id' => $companyId,
                'iot_device_id' => $device->getKey(),
                'employee_id' => $empOne?->getKey(),
                'attendance_id' => $empOneAttendance?->getKey(),
                'scanned_uid' => 'UID-EMP-001',
                'scan_type' => IotScanType::ClockIn,
                'status' => IotLogStatus::Processed,
                'device_timestamp' => $processedAt,
                'processed_at' => $processedAt,
                'raw_payload' => [
                    'uid' => 'UID-EMP-001',
                    'device_timestamp' => $processedAt->toIso8601String(),
                ],
                'response_message' => 'Absen Masuk OK',
            ]);

        $unregisteredAt = now()->subMinutes(5);

        IotAttendanceLog::query()
            ->withoutGlobalScope('company')
            ->create([
                'company_id' => $companyId,
                'iot_device_id' => $device->getKey(),
                'employee_id' => null,
                'attendance_id' => null,
                'scanned_uid' => 'UID-NEW-9999',
                'scan_type' => IotScanType::Unknown,
                'status' => IotLogStatus::UnregisteredUid,
                'device_timestamp' => $unregisteredAt,
                'processed_at' => $unregisteredAt,
                'raw_payload' => [
                    'uid' => 'UID-NEW-9999',
                    'device_timestamp' => $unregisteredAt->toIso8601String(),
                ],
                'response_message' => 'Kartu Tidak Terdaftar',
            ]);
    }
}
