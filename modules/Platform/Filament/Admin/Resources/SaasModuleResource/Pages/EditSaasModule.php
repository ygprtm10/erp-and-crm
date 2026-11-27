<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Admin\Resources\SaasModuleResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Platform\Filament\Admin\Resources\SaasModuleResource;

/**
 * Handles editing of an existing SaaS module definition.
 */
class EditSaasModule extends EditRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = SaasModuleResource::class;

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
