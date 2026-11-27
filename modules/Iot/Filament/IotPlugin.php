<?php

declare(strict_types=1);

namespace Modules\Iot\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Filament 5 plugin that registers all IoT ESP32 resources, pages, and widgets.
 */
class IotPlugin implements Plugin
{
    /**
     * Resolve a new instance of the IoT Filament plugin.
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
        return 'iot';
    }

    /**
     * Discover and register IoT UI components on the given Filament panel.
     */
    public function register(Panel $panel): void
    {
        $panel
            ->discoverResources(
                in: base_path('modules/Iot/Filament/Resources'),
                for: 'Modules\\Iot\\Filament\\Resources',
            )
            ->discoverPages(
                in: base_path('modules/Iot/Filament/Pages'),
                for: 'Modules\\Iot\\Filament\\Pages',
            )
            ->discoverWidgets(
                in: base_path('modules/Iot/Filament/Widgets'),
                for: 'Modules\\Iot\\Filament\\Widgets',
            );
    }

    /**
     * Execute runtime boot hooks when the Filament panel is active.
     */
    public function boot(Panel $panel): void
    {
        /** Add IoT panel runtime hooks here when required. */
    }
}
