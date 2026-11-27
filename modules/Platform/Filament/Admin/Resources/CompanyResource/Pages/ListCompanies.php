<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Admin\Resources\CompanyResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Platform\Filament\Admin\Resources\CompanyResource;

/**
 * Lists tenant Company records in the Super Admin panel.
 */
class ListCompanies extends ListRecords
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = CompanyResource::class;

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
