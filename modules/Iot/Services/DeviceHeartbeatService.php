<?php

declare(strict_types=1);

namespace Modules\Iot\Services;

use Illuminate\Support\Facades\DB;
use Modules\Iot\Models\IotDevice;

/**
 * Updates ESP32 device heartbeat timestamps and runtime network/firmware telemetry.
 */
class DeviceHeartbeatService
{
    /**
     * Record a heartbeat signal from an authenticated ESP32 device and return a compact payload.
     *
     * @return array{ok: bool, device_code: string, server_time: string, cooldown_seconds: int}
     */
    public function recordHeartbeat(
        IotDevice $device,
        ?string $firmwareVersion = null,
        ?string $macAddress = null,
        ?string $ipAddress = null,
    ): array {
        $now = now();

        DB::transaction(static function () use ($device, $firmwareVersion, $macAddress, $ipAddress, $now): void {
            $attributes = [
                'last_heartbeat_at' => $now,
            ];

            if ($firmwareVersion !== null && trim($firmwareVersion) !== '') {
                $attributes['firmware_version'] = trim($firmwareVersion);
            }

            if ($macAddress !== null && trim($macAddress) !== '') {
                $attributes['mac_address'] = trim($macAddress);
            }

            if ($ipAddress !== null && trim($ipAddress) !== '') {
                $attributes['last_ip_address'] = trim($ipAddress);
            }

            $device->update($attributes);
        });

        $device->refresh();

        return [
            'ok' => true,
            'device_code' => $device->device_code,
            'server_time' => $now->toIso8601String(),
            'cooldown_seconds' => $device->cooldown_seconds,
        ];
    }
}
