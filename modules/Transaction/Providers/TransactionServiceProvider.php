<?php

declare(strict_types=1);

namespace Modules\Transaction\Providers;

use App\Support\BaseModuleServiceProvider;
use Modules\Transaction\Services\OrderCreationService;
use Modules\Transaction\Services\OrderPaymentService;
use Modules\Transaction\Services\OrderStageService;
use Modules\Transaction\Services\PosShiftService;

/**
 * Service provider for the unified Transaction domain module.
 */
class TransactionServiceProvider extends BaseModuleServiceProvider
{
    /**
     * Return the module directory name.
     */
    protected function moduleName(): string
    {
        return 'Transaction';
    }

    /**
     * Register Transaction services in the container.
     */
    public function register(): void
    {
        $this->app->singleton(PosShiftService::class);
        $this->app->singleton(OrderCreationService::class);
        $this->app->singleton(OrderPaymentService::class);
        $this->app->singleton(OrderStageService::class);
    }
}
