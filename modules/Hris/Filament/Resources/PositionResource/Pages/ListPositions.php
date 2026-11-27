<?php

declare(strict_types=1);

namespace Modules\Hris\Filament\Resources\PositionResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Hris\Filament\Resources\PositionResource;

/**
 * Lists all job positions for the active tenant company.
 */
class ListPositions extends ListRecords
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = PositionResource::class;

    /**
     * Define header actions for creating a new job position.
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
