<?php

declare(strict_types=1);

namespace Modules\Transaction\Filament\Resources\PosTableResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Transaction\Filament\Resources\PosTableResource;

/**
 * Lists outlet QR tables with a Create action.
 */
class ListPosTables extends ListRecords
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = PosTableResource::class;

    /**
     * Expose the Create table header action.
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
