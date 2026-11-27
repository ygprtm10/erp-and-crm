<?php

declare(strict_types=1);

namespace Modules\Hris\Filament\Resources\DepartmentResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Hris\Filament\Resources\DepartmentResource;

/**
 * Creates a new organizational department for the active tenant company.
 */
class CreateDepartment extends CreateRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = DepartmentResource::class;
}
