<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Models\Company;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Modules\Accounting\Filament\AccountingPlugin;
use Modules\Core\Filament\CorePlugin;
use Modules\Hris\Filament\HrisPlugin;
use Modules\Iot\Filament\IotPlugin;
use Modules\Platform\Filament\PlatformTenantPlugin;
use Modules\Transaction\Filament\TransactionPlugin;

/**
 * Configures the multi-tenant Filament 5 application panel at /app/{company:slug}.
 */
class AppPanelProvider extends PanelProvider
{
    /**
     * Build the multi-tenant Filament panel configuration.
     */
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('app')
            ->login()
            ->tenant(Company::class, slugAttribute: 'slug')
            ->colors([
                'primary' => Color::Blue,
            ])
            ->viteTheme('resources/css/filament/app/theme.css')
            ->discoverResources(
                in: app_path('Filament/App/Resources'),
                for: 'App\\Filament\\App\\Resources',
            )
            ->discoverPages(
                in: app_path('Filament/App/Pages'),
                for: 'App\\Filament\\App\\Pages',
            )
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(
                in: app_path('Filament/App/Widgets'),
                for: 'App\\Filament\\App\\Widgets',
            )
            ->widgets([
                AccountWidget::class,
            ])
            ->plugins([
                PlatformTenantPlugin::make(),
                CorePlugin::make(),
                AccountingPlugin::make(),
                TransactionPlugin::make(),
                HrisPlugin::make(),
                IotPlugin::make(),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
