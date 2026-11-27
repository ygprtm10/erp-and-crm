<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources\ContactResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Core\Filament\Resources\ContactResource;

/**
 * Handles creation of a new CRM contact for the active tenant company.
 */
class CreateContact extends CreateRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = ContactResource::class;
}
