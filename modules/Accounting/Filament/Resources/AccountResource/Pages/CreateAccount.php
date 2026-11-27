<?php

declare(strict_types=1);

namespace Modules\Accounting\Filament\Resources\AccountResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Accounting\Filament\Resources\AccountResource;

/**
 * Handles creation of a custom Chart of Accounts entry for the active tenant.
 */
class CreateAccount extends CreateRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = AccountResource::class;
}
