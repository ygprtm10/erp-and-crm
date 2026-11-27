<?php

declare(strict_types=1);

namespace Modules\Iot\Filament\Resources\IotAttendanceLogResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Iot\Filament\Resources\IotAttendanceLogResource;

/**
 * Lists read-only ESP32 tap audit logs for the active tenant company.
 */
class ListIotAttendanceLogs extends ListRecords
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = IotAttendanceLogResource::class;
}
