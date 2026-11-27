<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources\ProductResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Core\Filament\Resources\ProductResource;

/**
 * Handles editing of an existing catalog product along with its variants and discounts.
 */
class EditProduct extends EditRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = ProductResource::class;

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
