<?php

declare(strict_types=1);

namespace Modules\Iot\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Iot\Http\Middleware\AuthenticateIotDevice;
use Modules\Iot\Http\Requests\IotHeartbeatRequest;
use Modules\Iot\Models\IotDevice;
use Modules\Iot\Services\DeviceHeartbeatService;

/**
 * Thin API controller handling ESP32 heartbeat telemetry updates.
 */
class IotHeartbeatController
{
    /**
     * Create a new heartbeat controller instance.
     */
    public function __construct(
        private readonly DeviceHeartbeatService $heartbeatService,
    ) {}

    /**
     * Record the authenticated ESP32 device heartbeat and return server metadata.
     */
    public function __invoke(IotHeartbeatRequest $request): JsonResponse
    {
        /** @var IotDevice $device */
        $device = $request->attributes->get(AuthenticateIotDevice::DEVICE_ATTRIBUTE);

        $payload = $this->heartbeatService->recordHeartbeat(
            device: $device,
            firmwareVersion: $request->validated('firmware_version'),
            macAddress: $request->validated('mac_address'),
            ipAddress: $request->ip(),
        );

        return response()->json($payload);
    }
}
