<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources\ProductResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Core\Filament\Resources\ProductResource;

/**
 * Handles creation of a new catalog product for the active tenant company.
 */
class CreateProduct extends CreateRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = ProductResource::class;
}
