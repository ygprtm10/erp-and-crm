<?php

declare(strict_types=1);

namespace Modules\Iot\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Iot\Http\Middleware\AuthenticateIotDevice;
use Modules\Iot\Http\Requests\IotAttendanceTapRequest;
use Modules\Iot\Models\IotDevice;
use Modules\Iot\Services\IotAttendanceIngestionService;

/**
 * Thin API controller delegating ESP32 card tap payloads to IotAttendanceIngestionService.
 */
class IotAttendanceTapController
{
    /**
     * Create a new attendance tap controller instance.
     */
    public function __construct(
        private readonly IotAttendanceIngestionService $ingestionService,
    ) {}

    /**
     * Ingest a scanned RFID/ID tap from the authenticated ESP32 hardware device.
     */
    public function __invoke(IotAttendanceTapRequest $request): JsonResponse
    {
        /** @var IotDevice $device */
        $device = $request->attributes->get(AuthenticateIotDevice::DEVICE_ATTRIBUTE);

        $payload = $this->ingestionService->ingestTap(
            device: $device,
            uid: (string) $request->validated('uid'),
            deviceTimestamp: $request->validated('device_timestamp'),
            rawPayload: $request->all(),
        );

        return response()->json($payload);
    }
}
