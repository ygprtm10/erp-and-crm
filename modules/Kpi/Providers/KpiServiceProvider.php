<?php

declare(strict_types=1);

namespace Modules\Kpi\Providers;

use App\Support\BaseModuleServiceProvider;
use Modules\Kpi\Services\KpiBonusCalculatorService;
use Modules\Kpi\Services\KpiEvaluationService;
use Modules\Kpi\Services\KpiMetricSyncService;

/**
 * Service provider for the KPI employee performance and bonus evaluation module.
 */
class KpiServiceProvider extends BaseModuleServiceProvider
{
    /**
     * Return the module directory name.
     */
    protected function moduleName(): string
    {
        return 'Kpi';
    }

    /**
     * Register KPI domain services in the service container.
     */
    public function register(): void
    {
        $this->app->singleton(KpiMetricSyncService::class);
        $this->app->singleton(KpiBonusCalculatorService::class);
        $this->app->singleton(KpiEvaluationService::class);
    }
}
