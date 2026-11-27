<?php

declare(strict_types=1);

namespace Modules\Core\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Filament 5 plugin that registers all Core tenant resources, pages, and widgets.
 */
class CorePlugin implements Plugin
{
    /**
     * Resolve a new instance of the Core Filament plugin.
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
        return 'core';
    }

    /**
     * Discover and register Core UI components on the given Filament panel.
     */
    public function register(Panel $panel): void
    {
        $panel
            ->discoverResources(
                in: base_path('modules/Core/Filament/Resources'),
                for: 'Modules\\Core\\Filament\\Resources',
            )
            ->discoverPages(
                in: base_path('modules/Core/Filament/Pages'),
                for: 'Modules\\Core\\Filament\\Pages',
            )
            ->discoverWidgets(
                in: base_path('modules/Core/Filament/Widgets'),
                for: 'Modules\\Core\\Filament\\Widgets',
            );
    }

    /**
     * Execute runtime boot hooks when the Filament panel is active.
     */
    public function boot(Panel $panel): void
    {
        /** Add Core panel runtime hooks here when required. */
    }
}
