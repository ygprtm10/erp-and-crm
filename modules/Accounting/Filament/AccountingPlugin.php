<?php

declare(strict_types=1);

namespace Modules\Accounting\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Filament 5 plugin that registers all Accounting resources, pages, and widgets.
 */
class AccountingPlugin implements Plugin
{
    /**
     * Resolve a new instance of the Accounting Filament plugin.
     */
    public static function make(): static
    {
        return app(static::class);
    }

    /**
     * Return the unique plugin identifier.
     */
    public function getId(): string
    {
        return 'accounting';
    }

    /**
     * Discover and register Accounting UI components on the given Filament panel.
     */
    public function register(Panel $panel): void
    {
        $panel
            ->discoverResources(
                in: base_path('modules/Accounting/Filament/Resources'),
                for: 'Modules\\Accounting\\Filament\\Resources',
            )
            ->discoverPages(
                in: base_path('modules/Accounting/Filament/Pages'),
                for: 'Modules\\Accounting\\Filament\\Pages',
            )
            ->discoverWidgets(
                in: base_path('modules/Accounting/Filament/Widgets'),
                for: 'Modules\\Accounting\\Filament\\Widgets',
            );
    }

    /**
     * Execute runtime boot hooks when the Filament panel is active.
     */
    public function boot(Panel $panel): void
    {
        /** Add Accounting panel runtime hooks here when required. */
    }
}
