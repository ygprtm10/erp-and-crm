<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Admin\Resources\SaasModuleResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Platform\Filament\Admin\Resources\SaasModuleResource;

/**
 * Handles creation of a new SaaS module definition.
 */
class CreateSaasModule extends CreateRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = SaasModuleResource::class;
}
