<?php

declare(strict_types=1);

namespace Modules\Hris\Filament\Resources\DepartmentResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Hris\Filament\Resources\DepartmentResource;

/**
 * Lists all organizational departments for the active tenant company.
 */
class ListDepartments extends ListRecords
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = DepartmentResource::class;

    /**
     * Define header actions for creating a new department.
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
