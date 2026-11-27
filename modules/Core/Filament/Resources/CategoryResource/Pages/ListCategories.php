<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources\CategoryResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Core\Filament\Resources\CategoryResource;

/**
 * Lists catalog categories belonging to the active tenant company.
 */
class ListCategories extends ListRecords
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = CategoryResource::class;

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
