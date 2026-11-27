<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Admin\Resources\SaasModuleResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Platform\Filament\Admin\Resources\SaasModuleResource;

/**
 * Lists SaaS modules in the Super Admin panel.
 */
class ListSaasModules extends ListRecords
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = SaasModuleResource::class;

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
