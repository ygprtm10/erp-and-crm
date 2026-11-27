<?php

declare(strict_types=1);

namespace Modules\Hris\Filament\Resources\LeaveRequestResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Modules\Hris\Filament\Resources\LeaveRequestResource;

/**
 * Lists employee leave requests with approval and rejection actions.
 */
class ListLeaveRequests extends ListRecords
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = LeaveRequestResource::class;

    /**
     * Expose the Ajukan Cuti / Izin action in the page header.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            LeaveRequestResource::makeSubmitLeaveAction(),
        ];
    }
}
