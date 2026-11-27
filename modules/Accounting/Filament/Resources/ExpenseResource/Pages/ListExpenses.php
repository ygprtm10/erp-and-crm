<?php

declare(strict_types=1);

namespace Modules\Accounting\Filament\Resources\ExpenseResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Accounting\Filament\Resources\ExpenseResource;

/**
 * Lists operational expenses belonging to the active tenant company.
 */
class ListExpenses extends ListRecords
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = ExpenseResource::class;

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
