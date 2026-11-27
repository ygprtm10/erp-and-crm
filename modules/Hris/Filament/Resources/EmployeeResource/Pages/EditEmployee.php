<?php

declare(strict_types=1);

namespace Modules\Hris\Filament\Resources\EmployeeResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Hris\Filament\Resources\EmployeeResource;

/**
 * Edits an existing employee master record belonging to the active tenant company.
 */
class EditEmployee extends EditRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = EmployeeResource::class;

    /**
     * Define header actions available while editing an employee.
     *
     * @return array<int, DeleteAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
