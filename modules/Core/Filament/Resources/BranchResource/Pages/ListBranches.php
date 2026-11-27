<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources\BranchResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Core\Filament\Resources\BranchResource;

/**
 * Lists operational branches belonging to the active tenant company.
 */
class ListBranches extends ListRecords
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = BranchResource::class;

    /**
     * Define header actions shown on the listing page.
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
