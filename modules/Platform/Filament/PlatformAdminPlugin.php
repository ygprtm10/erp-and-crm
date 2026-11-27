<?php

declare(strict_types=1);

namespace Modules\Platform\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Filament 5 plugin that registers Platform Super Admin resources, pages, and widgets.
 */
class PlatformAdminPlugin implements Plugin
{
    /**
     * Resolve a new instance of the Platform Admin Filament plugin.
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
        return 'platform-admin';
    }

    /**
     * Discover and register Platform Super Admin UI components on the given panel.
     */
    public function register(Panel $panel): void
    {
        $panel
            ->discoverResources(
                in: base_path('modules/Platform/Filament/Admin/Resources'),
                for: 'Modules\\Platform\\Filament\\Admin\\Resources',
            )
            ->discoverPages(
                in: base_path('modules/Platform/Filament/Admin/Pages'),
                for: 'Modules\\Platform\\Filament\\Admin\\Pages',
            )
            ->discoverWidgets(
                in: base_path('modules/Platform/Filament/Admin/Widgets'),
                for: 'Modules\\Platform\\Filament\\Admin\\Widgets',
            );
    }

    /**
     * Execute runtime boot hooks when the Filament panel is active.
     */
    public function boot(Panel $panel): void
    {
        /** Add Super Admin panel runtime hooks here when required. */
    }
}
