<?php

declare(strict_types=1);

namespace Modules\Iot\Services;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Core\Models\Branch;
use Modules\Iot\Data\ProvisionedDeviceResult;
use Modules\Iot\Enums\IotDeviceStatus;
use Modules\Iot\Enums\IotDeviceType;
use Modules\Iot\Models\IotDevice;

/**
 * Provisions ESP32 hardware devices and rotates SHA-256 hashed bearer tokens securely.
 */
class DeviceProvisioningService
{
    /**
     * Register a new ESP32 device with a freshly generated one-time plaintext token.
     */
    public function registerDevice(
        Company $company,
        string $deviceCode,
        string $name,
        IotDeviceType $deviceType = IotDeviceType::AttendanceRfid,
        ?Branch $branch = null,
        int $cooldownSeconds = 60,
        ?string $firmwareVersion = null,
        ?string $macAddress = null,
        ?string $notes = null,
    ): ProvisionedDeviceResult {
        if ($branch instanceof Branch && (int) $branch->company_id !== (int) $company->getKey()) {
            throw new InvalidArgumentException('Branch does not belong to the specified company.');
        }

        if ($cooldownSeconds < 0) {
            throw new InvalidArgumentException('Cooldown seconds must be zero or positive.');
        }

        $plainToken = $this->generatePlainToken();
        $tokenHash = hash('sha256', $plainToken);
        $tokenPrefix = substr($plainToken, 0, 12);

        $device = DB::transaction(static function () use (
            $company,
            $branch,
            $deviceCode,
            $name,
            $deviceType,
            $tokenHash,
            $tokenPrefix,
            $firmwareVersion,
            $macAddress,
            $cooldownSeconds,
            $notes,
        ): IotDevice {
            return IotDevice::query()
                ->withoutGlobalScope('company')
                ->create([
                    'company_id' => $company->getKey(),
                    'branch_id' => $branch?->getKey(),
                    'device_code' => trim($deviceCode),
                    'name' => trim($name),
                    'device_type' => $deviceType,
                    'api_token_hash' => $tokenHash,
                    'token_prefix' => $tokenPrefix,
                    'firmware_version' => $firmwareVersion,
                    'mac_address' => $macAddress,
                    'status' => IotDeviceStatus::Active,
                    'cooldown_seconds' => $cooldownSeconds,
                    'notes' => $notes,
                ]);
        });

        return new ProvisionedDeviceResult($device, $plainToken);
    }

    /**
     * Rotate an existing device token so the previous plaintext token is immediately invalidated.
     */
    public function rotateToken(IotDevice $device): string
    {
        $plainToken = $this->generatePlainToken();
        $tokenHash = hash('sha256', $plainToken);
        $tokenPrefix = substr($plainToken, 0, 12);

        DB::transaction(static function () use ($device, $tokenHash, $tokenPrefix): void {
            $device->update([
                'api_token_hash' => $tokenHash,
                'token_prefix' => $tokenPrefix,
            ]);
        });

        $device->refresh();

        return $plainToken;
    }

    /**
     * Generate a cryptographically random plaintext token prefixed with iot_.
     */
    private function generatePlainToken(): string
    {
        return 'iot_'.bin2hex(random_bytes(24));
    }
}
