<?php

declare(strict_types=1);

namespace Modules\Core\Filament\Resources\BranchResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Core\Filament\Resources\BranchResource;

/**
 * Handles creation of a new branch for the active tenant company.
 */
class CreateBranch extends CreateRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = BranchResource::class;
}
