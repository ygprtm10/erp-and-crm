<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Admin\Resources\CompanyResource\Pages;

use App\Models\Company;
use Filament\Resources\Pages\CreateRecord;
use Modules\Platform\Events\CompanyRegistered;
use Modules\Platform\Filament\Admin\Resources\CompanyResource;

/**
 * Handles creation of new tenant companies and dispatches CompanyRegistered.
 */
class CreateCompany extends CreateRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = CompanyResource::class;

    /**
     * Dispatch CompanyRegistered after a new tenant record is created.
     */
    protected function afterCreate(): void
    {
        /** @var Company $company */
        $company = $this->getRecord();

        CompanyRegistered::dispatch($company);
    }
}
