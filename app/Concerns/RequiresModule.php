<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\Company;
use Filament\Facades\Filament;

/**
 * Restricts Filament resource or page visibility based on active tenant modules.
 */
trait RequiresModule
{
    /**
     * Return the module slug required to access this Filament component.
     */
    abstract public static function requiredModuleSlug(): string;

    /**
     * Determine whether the current tenant has an active subscription for this module.
     */
    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        /** Deny access immediately if no tenant is resolved or the module is inactive. */
        if (! $tenant instanceof Company || ! $tenant->hasActiveModule(static::requiredModuleSlug())) {
            return false;
        }

        return parent::canAccess();
    }
}
