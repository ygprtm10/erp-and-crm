<?php

declare(strict_types=1);

namespace Modules\Hris\Filament\Resources\PayrollRunResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Modules\Hris\Filament\Resources\PayrollRunResource;

/**
 * Displays read-only payroll run summary, approval/pay actions, and employee payslips.
 */
class ViewPayrollRun extends ViewRecord
{
    /**
     * The parent Filament resource class.
     */
    protected static string $resource = PayrollRunResource::class;

    /**
     * Expose approve and pay workflow actions in the detail page header.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return PayrollRunResource::getWorkflowActions();
    }
}
