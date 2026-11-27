<?php

declare(strict_types=1);

namespace Modules\Accounting\Filament\Resources\AccountResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Accounting\Filament\Resources\AccountResource;

/**
 * Lists tenant Chart of Accounts entries and exposes the standard COA loader action.
 */
class ListAccounts extends ListRecords
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = AccountResource::class;

    /**
     * Define header actions shown on the Chart of Accounts listing page.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            AccountResource::makeSeedDefaultAccountsAction(),
            CreateAction::make(),
        ];
    }
}
