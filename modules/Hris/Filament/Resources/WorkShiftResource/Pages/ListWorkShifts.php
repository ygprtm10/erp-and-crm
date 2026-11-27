<?php

declare(strict_types=1);

namespace Modules\Hris\Filament\Resources\WorkShiftResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Hris\Filament\Resources\WorkShiftResource;

/**
 * Lists all work shift schedules for the active tenant company.
 */
class ListWorkShifts extends ListRecords
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = WorkShiftResource::class;

    /**
     * Define header actions for creating a new work shift.
     *
     * @return array<int, CreateAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
