<?php

declare(strict_types=1);

namespace Modules\Hris\Filament\Resources\WorkShiftResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Hris\Filament\Resources\WorkShiftResource;

/**
 * Edits an existing work shift schedule belonging to the active tenant company.
 */
class EditWorkShift extends EditRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = WorkShiftResource::class;

    /**
     * Define header actions available while editing a work shift.
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
