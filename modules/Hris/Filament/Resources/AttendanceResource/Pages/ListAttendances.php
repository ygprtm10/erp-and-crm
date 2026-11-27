<?php

declare(strict_types=1);

namespace Modules\Hris\Filament\Resources\AttendanceResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Modules\Hris\Filament\Resources\AttendanceResource;

/**
 * Lists employee attendance logs with service-backed clock-in and clock-out actions.
 */
class ListAttendances extends ListRecords
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = AttendanceResource::class;

    /**
     * Expose the Catat Clock-In action in the page header.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            AttendanceResource::makeClockInAction(),
        ];
    }
}
