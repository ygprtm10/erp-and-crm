<?php

declare(strict_types=1);

namespace Modules\Platform\Providers;

use App\Support\BaseModuleServiceProvider;
use Illuminate\Support\Facades\Event;
use Modules\Platform\Actions\ActivateCompanyModulesAction;
use Modules\Platform\Actions\UpdateCompanySettingsAction;
use Modules\Platform\Events\CompanyRegistered;
use Modules\Platform\Listeners\CreditTenantWalletOnOrderPaidListener;
use Modules\Platform\Listeners\InitializeTenantPlatformDefaults;
use Modules\Platform\Services\PaymentGatewayResolver;
use Modules\Platform\Services\TenantModuleCatalogService;
use Modules\Platform\Services\WalletLedgerService;
use Modules\Transaction\Events\OrderPaid;

/**
 * Service provider for the Platform SaaS core domain module.
 */
class PlatformServiceProvider extends BaseModuleServiceProvider
{
    /**
     * Return the module directory name.
     */
    protected function moduleName(): string
    {
        return 'Platform';
    }

    /**
     * Register Platform services and actions in the service container.
     */
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayResolver::class);
        $this->app->singleton(WalletLedgerService::class);
        $this->app->singleton(TenantModuleCatalogService::class);
        $this->app->singleton(ActivateCompanyModulesAction::class);
        $this->app->singleton(UpdateCompanySettingsAction::class);
    }

    /**
     * Subscribe Platform domain event listeners.
     */
    protected function bootListeners(): void
    {
        Event::listen(CompanyRegistered::class, InitializeTenantPlatformDefaults::class);
        Event::listen(OrderPaid::class, CreditTenantWalletOnOrderPaidListener::class);
    }
}
