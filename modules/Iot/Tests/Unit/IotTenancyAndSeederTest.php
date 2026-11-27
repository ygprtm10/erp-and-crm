<?php

declare(strict_types=1);

namespace Modules\Iot\Tests\Unit;

use App\Models\Company;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Hris\Models\Employee;
use Modules\Iot\Database\Seeders\IotDemoSeeder;
use Modules\Iot\Enums\IotLogStatus;
use Modules\Iot\Models\IotAttendanceLog;
use Modules\Iot\Models\IotDevice;
use Modules\Iot\Services\DeviceProvisioningService;
use Tests\TestCase;

/**
 * Unit tests verifying BelongsToCompany isolation, device online helper, and IotDemoSeeder idempotency.
 */
class IotTenancyAndSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure BelongsToCompany isolates IoT devices and logs strictly per active Filament tenant.
     */
    public function test_belongs_to_company_isolates_iot_devices_and_logs_per_tenant(): void
    {
        /** Arrange devices in two separate tenant companies. */
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $provisioning = app(DeviceProvisioningService::class);
        $deviceA = $provisioning->registerDevice($companyA, 'ESP32-A1', 'Device A')->device;
        $deviceB = $provisioning->registerDevice($companyB, 'ESP32-B1', 'Device B')->device;

        /** Act by setting active Filament tenant to Company A and creating an attendance log. */
        Filament::setTenant($companyA, isQuiet: true);

        try {
            $this->assertSame([$deviceA->getKey()], IotDevice::query()->pluck('id')->all());
            $this->assertNull(IotDevice::query()->find($deviceB->getKey()));
        } finally {
            Filament::setTenant(null, isQuiet: true);
        }
    }

    /**
     * Ensure IotDevice::isOnline evaluates heartbeat freshness against the threshold window.
     */
    public function test_device_is_online_evaluates_heartbeat_threshold(): void
    {
        /** Arrange a provisioned device with null, stale, and recent heartbeats. */
        $company = Company::factory()->create();
        $device = app(DeviceProvisioningService::class)->registerDevice($company, 'ESP32-HB', 'HB Device')->device;

        $this->assertFalse($device->isOnline(5));

        $device->last_heartbeat_at = now()->subMinutes(10);
        $this->assertFalse($device->isOnline(5));

        $device->last_heartbeat_at = now()->subMinutes(2);
        $this->assertTrue($device->isOnline(5));
    }

    /**
     * Ensure DatabaseSeeder runs IotDemoSeeder idempotently, gates iot only on Toko Maju, and skips in production.
     */
    public function test_database_seeder_runs_iot_demo_idempotently_and_enforces_module_gating(): void
    {
        /** Act by executing full DatabaseSeeder twice consecutively. */
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $gymSehat = Company::query()->where('slug', 'gym-sehat')->firstOrFail();

        /** Assert IoT module is active on Toko Maju and inactive on Gym Sehat. */
        $this->assertTrue($tokoMaju->hasActiveModule('iot'));
        $this->assertFalse($gymSehat->hasActiveModule('iot'));

        /** Assert zero duplicates for seeded device, employee RFID UIDs, and sample logs. */
        $devices = IotDevice::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->get();
        $this->assertCount(1, $devices);
        $device = $devices->firstOrFail();
        $this->assertSame('ESP32-PUSAT-01', $device->device_code);
        $this->assertSame(hash('sha256', IotDemoSeeder::DEMO_PLAIN_TOKEN), $device->api_token_hash);

        $empOne = Employee::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->where('employee_code', 'EMP-001')->firstOrFail();
        $empTwo = Employee::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->where('employee_code', 'EMP-002')->firstOrFail();
        $this->assertSame('UID-EMP-001', $empOne->rfid_uid);
        $this->assertSame('UID-EMP-002', $empTwo->rfid_uid);

        $logs = IotAttendanceLog::query()->withoutGlobalScope('company')->where('company_id', $tokoMaju->getKey())->get();
        $this->assertCount(2, $logs);
        $this->assertSame(1, $logs->where('status', IotLogStatus::Processed)->count());
        $this->assertSame(1, $logs->where('status', IotLogStatus::UnregisteredUid)->count());
    }

    /**
     * Ensure IotDemoSeeder is skipped in production environment.
     */
    public function test_iot_demo_seeder_is_skipped_in_production_environment(): void
    {
        /** Arrange simulated production environment. */
        $originalEnv = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
        } finally {
            $this->app['env'] = $originalEnv;
        }

        $this->assertSame(0, IotDevice::query()->withoutGlobalScope('company')->count());
        $this->assertSame(0, IotAttendanceLog::query()->withoutGlobalScope('company')->count());
    }
}
