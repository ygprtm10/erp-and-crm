<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Admin\Resources\SaasBundleResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Platform\Filament\Admin\Resources\SaasBundleResource;

/**
 * Handles creation of a new SaaS bundle.
 */
class CreateSaasBundle extends CreateRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = SaasBundleResource::class;
}
