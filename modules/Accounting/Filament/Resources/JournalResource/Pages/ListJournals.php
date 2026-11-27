<?php

declare(strict_types=1);

namespace Modules\Accounting\Filament\Resources\JournalResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Modules\Accounting\Filament\Resources\JournalResource;

/**
 * Lists general ledger journals belonging to the active tenant company.
 */
class ListJournals extends ListRecords
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = JournalResource::class;

    /**
     * Eager load journal items to compute table debit and credit totals efficiently.
     */
    protected function getTableQuery(): ?Builder
    {
        return parent::getTableQuery()?->with('items');
    }

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
