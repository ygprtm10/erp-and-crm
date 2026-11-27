<?php

declare(strict_types=1);

namespace Modules\Hris\Filament\Resources\PositionResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Hris\Filament\Resources\PositionResource;

/**
 * Edits an existing job position belonging to the active tenant company.
 */
class EditPosition extends EditRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = PositionResource::class;

    /**
     * Define header actions available while editing a job position.
     *
     * @return array<int, DeleteAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
