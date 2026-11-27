<?php

declare(strict_types=1);

namespace Modules\Transaction\Filament\Resources\OrderResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Modules\Transaction\Filament\Resources\OrderResource;

/**
 * Displays read-only order details with workflow actions and relation managers.
 */
class ViewOrder extends ViewRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = OrderResource::class;

    /**
     * Expose payment, stage update, and cancellation actions in the detail page header.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return OrderResource::getWorkflowActions();
    }
}
