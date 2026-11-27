<?php

declare(strict_types=1);

namespace Modules\Hris\Filament\Resources\PositionResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Hris\Filament\Resources\PositionResource;

/**
 * Creates a new job position for the active tenant company.
 */
class CreatePosition extends CreateRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = PositionResource::class;
}
