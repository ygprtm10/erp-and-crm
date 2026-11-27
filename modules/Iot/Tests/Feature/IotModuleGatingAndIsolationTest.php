<?php

declare(strict_types=1);

namespace Modules\Iot\Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Iot\Enums\IotLogStatus;
use Modules\Iot\Enums\IotScanType;
use Modules\Iot\Filament\IotPlugin;
use Modules\Iot\Filament\Pages\IotMonitorPage;
use Modules\Iot\Filament\Resources\IotAttendanceLogResource;
use Modules\Iot\Filament\Resources\IotAttendanceLogResource\Pages\ListIotAttendanceLogs;
use Modules\Iot\Filament\Resources\IotDeviceResource;
use Modules\Iot\Filament\Resources\IotDeviceResource\Pages\ListIotDevices;
use Modules\Iot\Models\IotAttendanceLog;
use Modules\Iot\Models\IotDevice;
use Modules\Iot\Services\DeviceProvisioningService;
use Modules\Platform\Actions\ActivateCompanyModulesAction;
use Modules\Platform\Models\SaasModule;
use Tests\TestCase;

/**
 * Feature tests verifying IotPlugin registration, RequiresModule 403 gating, and cross-tenant isolation.
 */
class IotModuleGatingAndIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure Toko Maju accesses all IoT resources and monitor page while Gym Sehat receives 403 Forbidden.
     */
    public function test_requires_module_grants_toko_maju_and_forbids_gym_sehat_across_all_iot_routes(): void
    {
        /** Arrange seeded Toko Maju (iot active) and Gym Sehat (iot inactive). */
        $this->seed(DatabaseSeeder::class);

        $appPanel = Filament::getPanel('app');
        $this->assertTrue($appPanel->hasPlugin('iot'));
        $this->assertSame('iot', IotPlugin::make()->getId());

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $owner2 = User::query()->where('email', 'owner2@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $gymSehat = Company::query()->where('slug', 'gym-sehat')->firstOrFail();

        /** Act & Assert 1: Toko Maju owner can access all IoT resources and the monitor guide page. */
        $this->actingAs($owner1);
        Filament::setCurrentPanel($appPanel);
        Filament::setTenant($tokoMaju, isQuiet: true);

        $this->assertTrue(IotDeviceResource::canAccess());
        $this->assertTrue(IotAttendanceLogResource::canAccess());
        $this->assertTrue(IotMonitorPage::canAccess());

        $this->get(IotDeviceResource::getUrl('index', ['tenant' => $tokoMaju]))->assertOk();
        $this->get(IotDeviceResource::getUrl('create', ['tenant' => $tokoMaju]))->assertOk();
        $this->get(IotAttendanceLogResource::getUrl('index', ['tenant' => $tokoMaju]))->assertOk();

        $this->get(IotMonitorPage::getUrl(['tenant' => $tokoMaju]))
            ->assertOk()
            ->assertSee('Panduan Endpoint API ESP32')
            ->assertSee('/api/v1/iot/heartbeat')
            ->assertSee('/api/v1/iot/attendance/tap');

        $this->flushSession();

        /** Act & Assert 2: Gym Sehat owner receives 403 Forbidden on every IoT route. */
        $this->actingAs($owner2);
        Filament::setTenant($gymSehat, isQuiet: true);

        $this->assertFalse(IotDeviceResource::canAccess());
        $this->assertFalse(IotAttendanceLogResource::canAccess());
        $this->assertFalse(IotMonitorPage::canAccess());

        $this->get(IotDeviceResource::getUrl('index', ['tenant' => $gymSehat]))->assertForbidden();
        $this->get(IotAttendanceLogResource::getUrl('index', ['tenant' => $gymSehat]))->assertForbidden();
        $this->get(IotMonitorPage::getUrl(['tenant' => $gymSehat]))->assertForbidden();
    }

    /**
     * Ensure IoT devices and tap logs remain strictly isolated across tenants when both have iot active.
     */
    public function test_iot_resources_enforce_cross_tenant_isolation_when_both_tenants_have_iot_active(): void
    {
        /** Arrange seeded Toko Maju and activate iot on Gym Sehat with its own device and tap log. */
        $this->seed(DatabaseSeeder::class);

        $owner1 = User::query()->where('email', 'owner@test.com')->firstOrFail();
        $owner2 = User::query()->where('email', 'owner2@test.com')->firstOrFail();
        $tokoMaju = Company::query()->where('slug', 'toko-maju')->firstOrFail();
        $gymSehat = Company::query()->where('slug', 'gym-sehat')->firstOrFail();

        $iotModule = SaasModule::query()->where('slug', 'iot')->firstOrFail();
        app(ActivateCompanyModulesAction::class)->execute($gymSehat, [$iotModule->getKey()]);

        $gymDevice = app(DeviceProvisioningService::class)->registerDevice(
            company: $gymSehat,
            deviceCode: 'ESP32-GYM-01',
            name: 'Gym Front Gate',
        )->device;

        $gymLog = IotAttendanceLog::query()->withoutGlobalScope('company')->create([
            'company_id' => $gymSehat->getKey(),
            'iot_device_id' => $gymDevice->getKey(),
            'scanned_uid' => 'UID-GYM-ONLY',
            'scan_type' => IotScanType::Unknown,
            'status' => IotLogStatus::UnregisteredUid,
            'processed_at' => now(),
        ]);

        $tokoDevice = IotDevice::query()->withoutGlobalScope('company')
            ->where('company_id', $tokoMaju->getKey())
            ->firstOrFail();
        $tokoLog = IotAttendanceLog::query()->withoutGlobalScope('company')
            ->where('company_id', $tokoMaju->getKey())
            ->firstOrFail();

        /** Assert Toko Maju sees only Toko Maju devices/logs and gets 404 for Gym Sehat device edit URL. */
        $this->actingAs($owner1);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($tokoMaju, isQuiet: true);

        Livewire::test(ListIotDevices::class)
            ->assertCanSeeTableRecords([$tokoDevice])
            ->assertCanNotSeeTableRecords([$gymDevice]);

        Livewire::test(ListIotAttendanceLogs::class)
            ->assertCanSeeTableRecords([$tokoLog])
            ->assertCanNotSeeTableRecords([$gymLog]);

        $this->get('/app/'.$tokoMaju->slug.'/iot-devices/'.$gymDevice->getKey().'/edit')->assertNotFound();

        $this->flushSession();

        /** Assert Gym Sehat sees only Gym Sehat devices and logs. */
        $this->actingAs($owner2);
        Filament::setTenant($gymSehat, isQuiet: true);

        Livewire::test(ListIotDevices::class)
            ->assertCanSeeTableRecords([$gymDevice])
            ->assertCanNotSeeTableRecords([$tokoDevice]);

        Livewire::test(ListIotAttendanceLogs::class)
            ->assertCanSeeTableRecords([$gymLog])
            ->assertCanNotSeeTableRecords([$tokoLog]);
    }
}
