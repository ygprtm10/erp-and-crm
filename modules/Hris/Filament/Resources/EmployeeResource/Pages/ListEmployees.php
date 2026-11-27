<?php

declare(strict_types=1);

namespace Modules\Hris\Filament\Resources\EmployeeResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Hris\Filament\Resources\EmployeeResource;

/**
 * Lists all employees belonging to the active tenant company.
 */
class ListEmployees extends ListRecords
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = EmployeeResource::class;

    /**
     * Define header actions for registering a new employee.
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
