<?php

declare(strict_types=1);

namespace Modules\Hris\Filament\Resources\EmployeeResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Hris\Filament\Resources\EmployeeResource;

/**
 * Creates a new employee master record for the active tenant company.
 */
class CreateEmployee extends CreateRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = EmployeeResource::class;
}
