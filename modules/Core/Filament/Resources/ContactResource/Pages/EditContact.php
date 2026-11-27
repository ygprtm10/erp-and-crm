<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources\ContactResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Core\Filament\Resources\ContactResource;

/**
 * Handles editing of an existing CRM contact and its interaction history.
 */
class EditContact extends EditRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = ContactResource::class;

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
