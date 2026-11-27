<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources\InventoryStockResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Modules\Core\Filament\Resources\InventoryStockResource;

/**
 * Lists branch inventory balances with an inbound Stock In header action.
 */
class ListInventoryStocks extends ListRecords
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = InventoryStockResource::class;

    /**
     * Expose the Stock In header action to add initial or inbound branch stock.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            InventoryStockResource::makeHeaderStockInAction(),
        ];
    }
}
