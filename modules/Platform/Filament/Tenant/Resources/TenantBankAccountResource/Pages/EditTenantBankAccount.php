<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Tenant\Resources\TenantBankAccountResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Platform\Filament\Tenant\Resources\TenantBankAccountResource;

/**
 * Handles editing of an existing tenant payout bank account.
 */
class EditTenantBankAccount extends EditRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = TenantBankAccountResource::class;

    /**
     * Define header actions shown on the edit page.
     *
     * @return array<int, DeleteAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
