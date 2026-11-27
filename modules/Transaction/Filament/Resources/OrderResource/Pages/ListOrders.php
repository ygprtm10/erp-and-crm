<?php

declare(strict_types=1);

namespace Modules\Transaction\Filament\Resources\OrderResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Transaction\Filament\Resources\OrderResource;

/**
 * Lists unified tenant transaction orders with channel, status, and date filters.
 */
class ListOrders extends ListRecords
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = OrderResource::class;
}
