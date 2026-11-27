<?php

declare(strict_types=1);

namespace Modules\Iot\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Iot\Enums\IotDeviceStatus;
use Modules\Iot\Models\IotDevice;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates incoming ESP32 hardware requests via SHA-256 device token lookup and module gating.
 */
class AuthenticateIotDevice
{
    /**
     * Request attribute key storing the authenticated IotDevice instance.
     */
    public const string DEVICE_ATTRIBUTE = 'iot_device';

    /**
     * Handle an incoming IoT device API request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = $this->extractToken($request);

        if ($plainToken === null) {
            return $this->errorResponse(
                status: Response::HTTP_UNAUTHORIZED,
                code: 'UNAUTHORIZED_DEVICE',
                message: 'Missing device authentication token.',
            );
        }

        $tokenHash = hash('sha256', $plainToken);

        /** Query outside Filament tenant global scope because hardware devices authenticate headlessly. */
        $device = IotDevice::query()
            ->withoutGlobalScope('company')
            ->with('company')
            ->where('api_token_hash', $tokenHash)
            ->first();

        if (! $device instanceof IotDevice) {
            return $this->errorResponse(
                status: Response::HTTP_UNAUTHORIZED,
                code: 'UNAUTHORIZED_DEVICE',
                message: 'Invalid device authentication token.',
            );
        }

        if ($device->status !== IotDeviceStatus::Active) {
            return $this->errorResponse(
                status: Response::HTTP_FORBIDDEN,
                code: 'DEVICE_INACTIVE',
                message: 'Device is not active.',
            );
        }

        $company = $device->company;

        if (! $company instanceof Company || ! $company->hasActiveModule('iot') || ! $company->hasActiveModule('hris')) {
            return $this->errorResponse(
                status: Response::HTTP_FORBIDDEN,
                code: 'MODULE_INACTIVE',
                message: 'Required IoT and HRIS modules are not active for this company.',
            );
        }

        $request->attributes->set(self::DEVICE_ATTRIBUTE, $device);

        return $next($request);
    }

    /**
     * Extract the raw device token from Authorization Bearer or X-Device-Token headers.
     */
    private function extractToken(Request $request): ?string
    {
        $bearerToken = $request->bearerToken();

        if (is_string($bearerToken) && trim($bearerToken) !== '') {
            return trim($bearerToken);
        }

        $headerToken = $request->header('X-Device-Token');

        if (is_string($headerToken) && trim($headerToken) !== '') {
            return trim($headerToken);
        }

        return null;
    }

    /**
     * Build a compact JSON error payload suitable for ESP32 HTTP clients.
     */
    private function errorResponse(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => $code,
            'message' => $message,
        ], $status);
    }
}
