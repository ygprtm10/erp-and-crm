<?php

declare(strict_types=1);

namespace Modules\Iot\Enums;

/**
 * Supported hardware reader types for ESP32 IoT attendance devices.
 */
enum IotDeviceType: string
{
    case AttendanceRfid = 'attendance_rfid';
    case AttendanceFingerprint = 'attendance_fingerprint';
    case AttendanceHybrid = 'attendance_hybrid';

    /**
     * Return a human-readable label for the hardware device type.
     */
    public function label(): string
    {
        return match ($this) {
            self::AttendanceRfid => 'Attendance RFID',
            self::AttendanceFingerprint => 'Attendance Fingerprint',
            self::AttendanceHybrid => 'Attendance Hybrid',
        };
    }
}
