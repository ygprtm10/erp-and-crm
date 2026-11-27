<?php

declare(strict_types=1);

namespace Modules\Hris\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Filament 5 plugin that registers all HRIS & Payroll resources, pages, and widgets.
 */
class HrisPlugin implements Plugin
{
    /**
     * Resolve a new instance of the HRIS Filament plugin.
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
        return 'hris';
    }

    /**
     * Discover and register HRIS UI components on the given Filament panel.
     */
    public function register(Panel $panel): void
    {
        $panel
            ->discoverResources(
                in: base_path('modules/Hris/Filament/Resources'),
                for: 'Modules\\Hris\\Filament\\Resources',
            )
            ->discoverPages(
                in: base_path('modules/Hris/Filament/Pages'),
                for: 'Modules\\Hris\\Filament\\Pages',
            )
            ->discoverWidgets(
                in: base_path('modules/Hris/Filament/Widgets'),
                for: 'Modules\\Hris\\Filament\\Widgets',
            );
    }

    /**
     * Execute runtime boot hooks when the Filament panel is active.
     */
    public function boot(Panel $panel): void
    {
        /** Add HRIS panel runtime hooks here when required. */
    }
}
