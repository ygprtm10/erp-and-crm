<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources\InventoryStockResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Modules\Core\Filament\Resources\InventoryStockResource;

/**
 * Displays a branch-variant stock balance together with its read-only stock movement audit trail.
 */
class ViewInventoryStock extends ViewRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = InventoryStockResource::class;

    /**
     * Expose Stock In, Stock Out, Adjust, and Transfer actions in the view page header.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return InventoryStockResource::getRowStockActions();
    }
}
