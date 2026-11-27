<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Iot\Http\Controllers\IotAttendanceTapController;
use Modules\Iot\Http\Controllers\IotHeartbeatController;
use Modules\Iot\Http\Middleware\AuthenticateIotDevice;

/**
 * Versioned v1 REST API routes for ESP32 hardware heartbeat and attendance tap ingestion.
 */
Route::prefix('v1/iot')
    ->middleware(['throttle:120,1', AuthenticateIotDevice::class])
    ->group(function (): void {
        Route::post('/heartbeat', IotHeartbeatController::class)
            ->name('api.v1.iot.heartbeat');

        Route::post('/attendance/tap', IotAttendanceTapController::class)
            ->name('api.v1.iot.attendance.tap');
    });
