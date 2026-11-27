<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Tenant\Resources\TenantBankAccountResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Platform\Filament\Tenant\Resources\TenantBankAccountResource;

/**
 * Lists payout bank accounts belonging to the active tenant company.
 */
class ListTenantBankAccounts extends ListRecords
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = TenantBankAccountResource::class;

    /**
     * Define header actions shown on the listing page.
     *
     * @return array<int, CreateAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
