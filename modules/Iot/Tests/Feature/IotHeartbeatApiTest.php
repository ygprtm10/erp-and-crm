<?php

declare(strict_types=1);

namespace Modules\Iot\Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Iot\Enums\IotDeviceStatus;
use Modules\Iot\Services\DeviceProvisioningService;
use Modules\Platform\Actions\ActivateCompanyModulesAction;
use Modules\Platform\Database\Seeders\SaasModuleSeeder;
use Modules\Platform\Enums\CompanyModuleStatus;
use Modules\Platform\Models\CompanyModule;
use Modules\Platform\Models\SaasModule;
use Tests\TestCase;

/**
 * Feature tests verifying ESP32 device authentication middleware and /api/v1/iot/heartbeat telemetry.
 */
class IotHeartbeatApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure a valid device token updates heartbeat timestamp, telemetry fields, and returns compact JSON.
     */
    public function test_valid_device_heartbeat_updates_telemetry_and_returns_compact_payload(): void
    {
        /** Arrange a company with active iot + hris modules and a provisioned active device. */
        [$company, $device, $plainToken] = $this->createAuthenticatedDeviceFixture();

        /** Act by posting heartbeat via Authorization Bearer token. */
        $response = $this->withToken($plainToken)->postJson('/api/v1/iot/heartbeat', [
            'firmware_version' => '1.2.5',
            'mac_address' => '11:22:33:44:55:66',
        ]);

        /** Assert HTTP 200 compact payload and persisted telemetry updates. */
        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('device_code', 'ESP32-HB-01')
            ->assertJsonPath('cooldown_seconds', 60)
            ->assertJsonStructure(['ok', 'device_code', 'server_time', 'cooldown_seconds']);

        $device->refresh();
        $this->assertNotNull($device->last_heartbeat_at);
        $this->assertSame('1.2.5', $device->firmware_version);
        $this->assertSame('11:22:33:44:55:66', $device->mac_address);
        $this->assertNotNull($device->last_ip_address);
    }

    /**
     * Ensure X-Device-Token header is also accepted as a valid hardware authentication mechanism.
     */
    public function test_accepts_x_device_token_header_for_heartbeat(): void
    {
        /** Arrange a provisioned device with active modules. */
        [, , $plainToken] = $this->createAuthenticatedDeviceFixture();

        /** Act by posting heartbeat using X-Device-Token header. */
        $response = $this->withHeader('X-Device-Token', $plainToken)
            ->postJson('/api/v1/iot/heartbeat');

        $response->assertOk()->assertJsonPath('ok', true);
    }

    /**
     * Ensure missing or invalid tokens return 401 Unauthorized.
     */
    public function test_missing_or_invalid_token_returns_unauthorized(): void
    {
        /** Arrange valid module catalog without matching device token. */
        $this->seed(SaasModuleSeeder::class);

        $this->postJson('/api/v1/iot/heartbeat')
            ->assertUnauthorized()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'UNAUTHORIZED_DEVICE');

        $this->withToken('iot_invalid_token_value')
            ->postJson('/api/v1/iot/heartbeat')
            ->assertUnauthorized()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'UNAUTHORIZED_DEVICE');
    }

    /**
     * Ensure inactive/maintenance devices and tenants missing iot or hris modules receive 403 Forbidden.
     */
    public function test_inactive_device_or_missing_modules_return_forbidden(): void
    {
        /** Arrange an active device whose status is switched to inactive, plus a tenant missing hris. */
        [$company, $device, $plainToken] = $this->createAuthenticatedDeviceFixture();

        $device->update(['status' => IotDeviceStatus::Inactive]);

        $this->withToken($plainToken)
            ->postJson('/api/v1/iot/heartbeat')
            ->assertForbidden()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'DEVICE_INACTIVE');

        /** Re-activate device but expire/remove hris module entitlement. */
        $device->update(['status' => IotDeviceStatus::Active]);
        $hrisModule = SaasModule::query()->where('slug', 'hris')->firstOrFail();

        CompanyModule::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->where('module_id', $hrisModule->getKey())
            ->delete();

        $this->withToken($plainToken)
            ->postJson('/api/v1/iot/heartbeat')
            ->assertForbidden()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'MODULE_INACTIVE');

        /** Restore hris and remove iot module entitlement. */
        app(ActivateCompanyModulesAction::class)->execute(
            company: $company,
            moduleIds: [$hrisModule->getKey()],
            expiresAt: now()->addMonth(),
            status: CompanyModuleStatus::Active,
        );

        $iotModule = SaasModule::query()->where('slug', 'iot')->firstOrFail();
        CompanyModule::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $company->getKey())
            ->where('module_id', $iotModule->getKey())
            ->delete();

        $this->withToken($plainToken)
            ->postJson('/api/v1/iot/heartbeat')
            ->assertForbidden()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'MODULE_INACTIVE');
    }

    /**
     * Create a company with active iot and hris entitlements plus a provisioned active device.
     *
     * @return array{0: Company, 1: \Modules\Iot\Models\IotDevice, 2: string}
     */
    private function createAuthenticatedDeviceFixture(): array
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

        $provisioned = app(DeviceProvisioningService::class)->registerDevice(
            company: $company,
            deviceCode: 'ESP32-HB-01',
            name: 'Heartbeat Test Terminal',
        );

        return [$company, $provisioned->device, $provisioned->plainToken];
    }
}
