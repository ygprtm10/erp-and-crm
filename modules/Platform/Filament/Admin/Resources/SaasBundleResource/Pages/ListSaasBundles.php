<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Admin\Resources\SaasBundleResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\Platform\Filament\Admin\Resources\SaasBundleResource;

/**
 * Lists SaaS bundles in the Super Admin panel.
 */
class ListSaasBundles extends ListRecords
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = SaasBundleResource::class;

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
