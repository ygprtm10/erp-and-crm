<?php

declare(strict_types=1);

namespace Modules\Hris\Enums;

/**
 * Capture sources for employee attendance events.
 */
enum AttendanceSource: string
{
    case Manual = 'manual';
    case Web = 'web';
    case IotEsp32 = 'iot_esp32';
}
