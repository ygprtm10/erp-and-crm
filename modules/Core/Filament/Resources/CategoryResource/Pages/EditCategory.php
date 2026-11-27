<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources\CategoryResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Core\Filament\Resources\CategoryResource;

/**
 * Handles editing of an existing catalog category.
 */
class EditCategory extends EditRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = CategoryResource::class;

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
