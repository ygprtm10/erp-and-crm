<?php

declare(strict_types=1);

namespace Modules\Accounting\Filament\Resources\AccountResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Accounting\Filament\Resources\AccountResource;

/**
 * Handles editing of a tenant Chart of Accounts entry.
 */
class EditAccount extends EditRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = AccountResource::class;

    /**
     * Define header actions shown on the edit page.
     *
     * @return array<int, DeleteAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => AccountResource::canDelete($this->getRecord())),
        ];
    }
}
