<?php

declare(strict_types=1);

namespace Modules\Core\Providers;

use App\Support\BaseModuleServiceProvider;
use Illuminate\Support\Facades\Event;
use Modules\Core\Listeners\DeductOrderStockListener;
use Modules\Core\Listeners\ProvisionMainBranchForCompany;
use Modules\Core\Services\PricingCalculatorService;
use Modules\Core\Services\StockMovementService;
use Modules\Platform\Events\CompanyRegistered;
use Modules\Transaction\Events\OrderPaid;

/**
 * Service provider for the Core domain module (branches, CRM, products, and inventory).
 */
class CoreServiceProvider extends BaseModuleServiceProvider
{
    /**
     * Return the module directory name.
     */
    protected function moduleName(): string
    {
        return 'Core';
    }

    /**
     * Register Core domain services in the container.
     */
    public function register(): void
    {
        $this->app->singleton(StockMovementService::class);
        $this->app->singleton(PricingCalculatorService::class);
    }

    /**
     * Subscribe Core listeners to Platform lifecycle events.
     */
    protected function bootListeners(): void
    {
        Event::listen(CompanyRegistered::class, ProvisionMainBranchForCompany::class);
        Event::listen(OrderPaid::class, DeductOrderStockListener::class);
    }
}
