<?php

declare(strict_types=1);

namespace Modules\Platform\Filament\Tenant\Pages;

use App\Models\Company;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use Modules\Platform\Services\TenantModuleCatalogService;
use UnitEnum;

/**
 * Displays active/trial/expired module entitlements and available add-on modules for the tenant.
 */
class ActiveModulesPage extends Page
{
    /**
     * The Blade view rendered by this Filament page.
     */
    protected string $view = 'platform::filament.tenant.pages.active-modules';

    /**
     * The sidebar navigation icon for this page.
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    /**
     * The navigation label shown in the tenant sidebar.
     */
    protected static ?string $navigationLabel = 'Active Modules';

    /**
     * The navigation group in the tenant sidebar.
     */
    protected static string|UnitEnum|null $navigationGroup = 'Billing & Payouts';

    /**
     * The page title displayed in the header.
     */
    protected static ?string $title = 'Active Modules & Add-Ons';

    /**
     * Return pre-formatted module entitlements owned by the current tenant.
     *
     * @return list<array{
     *     name: string,
     *     slug: string,
     *     status_label: string,
     *     status_color: string,
     *     expires_at_label: string,
     *     formatted_monthly_price: string
     * }>
     */
    #[Computed]
    public function ownedModules(): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company) {
            return [];
        }

        return app(TenantModuleCatalogService::class)->getCatalogForCompany($tenant)['owned'];
    }

    /**
     * Return pre-formatted add-on modules not yet subscribed by the current tenant.
     *
     * @return list<array{
     *     name: string,
     *     slug: string,
     *     formatted_monthly_price: string,
     *     required_modules_text: string
     * }>
     */
    #[Computed]
    public function availableAddOns(): array
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company) {
            return [];
        }

        return app(TenantModuleCatalogService::class)->getCatalogForCompany($tenant)['available_addons'];
    }
}
