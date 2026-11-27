<?php

declare(strict_types=1);

namespace Modules\Hris\Filament\Resources\DepartmentResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Hris\Filament\Resources\DepartmentResource;

/**
 * Edits an existing organizational department belonging to the active tenant company.
 */
class EditDepartment extends EditRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = DepartmentResource::class;

    /**
     * Define header actions available while editing a department.
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
