<?php

declare(strict_types=1);

namespace Modules\Hris\Providers;

use App\Support\BaseModuleServiceProvider;
use Modules\Hris\Services\AttendanceService;
use Modules\Hris\Services\LeaveRequestService;
use Modules\Hris\Services\PayrollCalculationService;
use Modules\Hris\Services\TaxAndBpjsCalculatorService;

/**
 * Service provider for the HRIS, attendance, leave, and payroll domain module.
 */
class HrisServiceProvider extends BaseModuleServiceProvider
{
    /**
     * Return the module directory name.
     */
    protected function moduleName(): string
    {
        return 'Hris';
    }

    /**
     * Register HRIS domain services in the service container.
     */
    public function register(): void
    {
        $this->app->singleton(AttendanceService::class);
        $this->app->singleton(LeaveRequestService::class);
        $this->app->singleton(TaxAndBpjsCalculatorService::class);
        $this->app->singleton(PayrollCalculationService::class);
    }
}
