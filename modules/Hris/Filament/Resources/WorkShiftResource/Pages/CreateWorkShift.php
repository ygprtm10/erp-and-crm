<?php

declare(strict_types=1);

namespace Modules\Hris\Filament\Resources\WorkShiftResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Hris\Filament\Resources\WorkShiftResource;

/**
 * Creates a new work shift schedule for the active tenant company.
 */
class CreateWorkShift extends CreateRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = WorkShiftResource::class;
}
