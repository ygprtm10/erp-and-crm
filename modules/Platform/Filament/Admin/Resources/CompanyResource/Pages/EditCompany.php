<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Admin\Resources\CompanyResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Platform\Filament\Admin\Resources\CompanyResource;

/**
 * Handles editing of an existing tenant Company record.
 */
class EditCompany extends EditRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = CompanyResource::class;

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
