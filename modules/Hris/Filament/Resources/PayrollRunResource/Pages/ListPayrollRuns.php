<?php

declare(strict_types=1);

namespace Modules\Hris\Filament\Resources\PayrollRunResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Modules\Hris\Filament\Resources\PayrollRunResource;

/**
 * Lists monthly payroll runs for the active tenant company.
 */
class ListPayrollRuns extends ListRecords
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = PayrollRunResource::class;

    /**
     * Expose the Generate Payroll action in the page header.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            PayrollRunResource::makeGeneratePayrollAction(),
        ];
    }
}
