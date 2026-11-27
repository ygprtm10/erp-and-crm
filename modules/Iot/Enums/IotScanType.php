<?php

declare(strict_types=1);

namespace Modules\Iot\Enums;

/**
 * Interpreted attendance action types resulting from an IoT tap event.
 */
enum IotScanType: string
{
    case ClockIn = 'clock_in';
    case ClockOut = 'clock_out';
    case Unknown = 'unknown';
    case Ignored = 'ignored';
}
