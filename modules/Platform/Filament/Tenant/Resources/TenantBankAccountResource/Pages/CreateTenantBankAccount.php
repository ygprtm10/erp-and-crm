<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Tenant\Resources\TenantBankAccountResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Platform\Filament\Tenant\Resources\TenantBankAccountResource;

/**
 * Handles creation of a new payout bank account for the active tenant company.
 */
class CreateTenantBankAccount extends CreateRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = TenantBankAccountResource::class;
}
