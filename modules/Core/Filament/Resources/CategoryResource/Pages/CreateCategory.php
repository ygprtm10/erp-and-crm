<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources\CategoryResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Core\Filament\Resources\CategoryResource;

/**
 * Handles creation of a new catalog category for the active tenant company.
 */
class CreateCategory extends CreateRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = CategoryResource::class;
}
