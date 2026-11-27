<?php

declare(strict_types=1);

namespace Modules\Transaction\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Filament 5 plugin that registers all Transaction & POS resources, pages, and widgets.
 */
class TransactionPlugin implements Plugin
{
    /**
     * Resolve a new instance of the Transaction Filament plugin.
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
        return 'transaction';
    }

    /**
     * Discover and register Transaction UI components on the given Filament panel.
     */
    public function register(Panel $panel): void
    {
        $panel
            ->discoverResources(
                in: base_path('modules/Transaction/Filament/Resources'),
                for: 'Modules\\Transaction\\Filament\\Resources',
            )
            ->discoverPages(
                in: base_path('modules/Transaction/Filament/Pages'),
                for: 'Modules\\Transaction\\Filament\\Pages',
            )
            ->discoverWidgets(
                in: base_path('modules/Transaction/Filament/Widgets'),
                for: 'Modules\\Transaction\\Filament\\Widgets',
            );
    }

    /**
     * Execute runtime boot hooks when the Filament panel is active.
     */
    public function boot(Panel $panel): void
    {
        /** Add Transaction panel runtime hooks here when required. */
    }
}
