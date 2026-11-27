<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources\BranchResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Core\Filament\Resources\BranchResource;

/**
 * Handles editing of an existing tenant branch.
 */
class EditBranch extends EditRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = BranchResource::class;

    /**
     * Define header actions shown on the edit page.
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
