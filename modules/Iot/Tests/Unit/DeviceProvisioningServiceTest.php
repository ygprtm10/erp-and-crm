<?php

declare(strict_types=1);

namespace Modules\Iot\Tests\Unit;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Core\Models\Branch;
use Modules\Iot\Enums\IotDeviceStatus;
use Modules\Iot\Enums\IotDeviceType;
use Modules\Iot\Services\DeviceProvisioningService;
use Tests\TestCase;

/**
 * Unit tests verifying secure device token hashing, one-time reveal, and token rotation.
 */
class DeviceProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ensure device registration returns a one-time plaintext token while persisting only its SHA-256 hash.
     */
    public function test_registers_device_with_hashed_token_and_prefix_only(): void
    {
        /** Arrange a company and main branch. */
        $company = Company::factory()->create();
        $branch = Branch::query()->withoutGlobalScope('company')->create([
            'company_id' => $company->getKey(),
            'code' => 'MAIN',
            'name' => 'Main Branch',
            'is_main' => true,
        ]);

        $service = app(DeviceProvisioningService::class);

        /** Act by provisioning a new RFID attendance terminal. */
        $result = $service->registerDevice(
            company: $company,
            deviceCode: 'ESP32-MAIN-01',
            name: 'Lobby Terminal',
            deviceType: IotDeviceType::AttendanceRfid,
            branch: $branch,
            cooldownSeconds: 45,
        );

        /** Assert plaintext token starts with iot_ and only its SHA-256 hash is persisted. */
        $this->assertStringStartsWith('iot_', $result->plainToken);
        $this->assertSame(hash('sha256', $result->plainToken), $result->device->api_token_hash);
        $this->assertSame(substr($result->plainToken, 0, 12), $result->device->token_prefix);
        $this->assertNotSame($result->plainToken, $result->device->api_token_hash);
        $this->assertSame(IotDeviceStatus::Active, $result->device->status);
        $this->assertSame(45, $result->device->cooldown_seconds);
        $this->assertArrayNotHasKey('api_token_hash', $result->device->toArray());
    }

    /**
     * Ensure rotating a device token invalidates the old token hash and activates the new token.
     */
    public function test_rotates_device_token_and_invalidates_previous_token(): void
    {
        /** Arrange a provisioned IoT device. */
        $company = Company::factory()->create();
        $service = app(DeviceProvisioningService::class);
        $initial = $service->registerDevice($company, 'ESP32-ROT-01', 'Warehouse Gate');
        $oldHash = $initial->device->api_token_hash;

        /** Act by rotating the device token. */
        $newPlainToken = $service->rotateToken($initial->device);

        /** Assert the old token hash is replaced and the new token hash matches. */
        $this->assertNotSame($initial->plainToken, $newPlainToken);
        $this->assertNotSame($oldHash, $initial->device->api_token_hash);
        $this->assertSame(hash('sha256', $newPlainToken), $initial->device->api_token_hash);
        $this->assertSame(substr($newPlainToken, 0, 12), $initial->device->token_prefix);
    }

    /**
     * Ensure provisioning rejects a branch belonging to another company.
     */
    public function test_rejects_cross_company_branch_during_provisioning(): void
    {
        /** Arrange two companies with a branch belonging to company B. */
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $foreignBranch = Branch::query()->withoutGlobalScope('company')->create([
            'company_id' => $companyB->getKey(),
            'code' => 'FOREIGN',
            'name' => 'Foreign Branch',
            'is_main' => true,
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(DeviceProvisioningService::class)->registerDevice(
            company: $companyA,
            deviceCode: 'ESP32-ERR-01',
            name: 'Invalid Terminal',
            branch: $foreignBranch,
        );
    }
}
