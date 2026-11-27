<?php

declare(strict_types=1);

namespace Modules\Transaction\Filament\Resources\PosShiftResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Modules\Transaction\Filament\Resources\PosShiftResource;

/**
 * Lists cashier shifts and exposes the Open Shift header action.
 */
class ListPosShifts extends ListRecords
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = PosShiftResource::class;

    /**
     * Expose the Buka Shift header action.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            PosShiftResource::makeOpenShiftHeaderAction(),
        ];
    }
}
