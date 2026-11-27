<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Admin\Resources\SaasBundleResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Platform\Filament\Admin\Resources\SaasBundleResource;

/**
 * Handles editing of an existing SaaS bundle.
 */
class EditSaasBundle extends EditRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = SaasBundleResource::class;

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
