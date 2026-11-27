<?php

declare(strict_types=1);

namespace Modules\Platform\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Filament 5 plugin that registers Platform tenant-facing resources, pages, and widgets.
 */
class PlatformTenantPlugin implements Plugin
{
    /**
     * Resolve a new instance of the Platform Tenant Filament plugin.
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
        return 'platform-tenant';
    }

    /**
     * Discover and register Platform tenant UI components on the given panel.
     */
    public function register(Panel $panel): void
    {
        $panel
            ->discoverResources(
                in: base_path('modules/Platform/Filament/Tenant/Resources'),
                for: 'Modules\\Platform\\Filament\\Tenant\\Resources',
            )
            ->discoverPages(
                in: base_path('modules/Platform/Filament/Tenant/Pages'),
                for: 'Modules\\Platform\\Filament\\Tenant\\Pages',
            )
            ->discoverWidgets(
                in: base_path('modules/Platform/Filament/Tenant/Widgets'),
                for: 'Modules\\Platform\\Filament\\Tenant\\Widgets',
            );
    }

    /**
     * Execute runtime boot hooks when the Filament panel is active.
     */
    public function boot(Panel $panel): void
    {
        /** Add tenant panel runtime hooks here when required. */
    }
}
